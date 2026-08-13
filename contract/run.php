<?php

declare(strict_types=1);

/**
 * Runs the PHP SDK over REAL HTTP against the TypeScript repo's contract mock
 * (examples/host-demo/mock-gurb.mjs, copied here unmodified).
 *
 * Every endpoint the SDK can issue is exercised, and for each one the harness
 * asserts the verb, the full URL, the request headers, the exact JSON body, the
 * HTTP status that came back, and the decoded model. A wrong path shows up as a
 * 404 from the mock; a wrong body shows up as a 400.
 */

require (\getenv('SDK_PATH') ?: \dirname(__DIR__)) . '/vendor/autoload.php';
require __DIR__ . '/Recording.php';

use Contract\Checks;
use Contract\Recording;
use Gurb\ApiKeyScope;
use Gurb\CommunityRequestStatus;
use Gurb\CommunityRole;
use Gurb\EmbedSection;
use Gurb\GurbAdminClient;
use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\GurbErrorCode;
use Gurb\Http\CurlHttpClient;
use Gurb\Http\HttpRequest;
use Gurb\Input\BulkMemberInput;
use Gurb\Input\CreateCommunityInput;
use Gurb\Model\ApiKeyWithSecret;
use Gurb\Permission;
use Gurb\Resource\MembersResource;

$base = \getenv('MOCK_BASE_URL') ?: 'http://host.docker.internal:5099';

$COMMUNITY_KEY = 'gurb_a1b2c3d4e5f6_' . \str_repeat('x', 43);
$ADMIN_KEY = 'gurb_sa_a1b2c3d4e5f6_' . \str_repeat('x', 43);

$rec = new Recording();
$adminRec = new Recording();

$gurb = new GurbClient($COMMUNITY_KEY, $base, $rec);
$admin = new GurbAdminClient($ADMIN_KEY, $base, $adminRec);

$c = new Checks();

/** Assert verb + URL + headers + body of the most recent recorded call. */
$wire = static function (
    Checks $c,
    Recording $r,
    string $method,
    string $url,
    ?array $body,
    int $status,
    string $key,
    string $label,
): void {
    $call = $r->last();
    /** @var HttpRequest $req */
    $req = $call['req'];

    $c->same($method, $req->method, "{$label}: verb");
    $c->same($url, $req->url, "{$label}: url");
    $c->same($status, $call['status'], "{$label}: status");
    $c->same($key, $req->headers['X-Api-Key'] ?? null, "{$label}: X-Api-Key header");
    $c->same('application/json', $req->headers['Accept'] ?? null, "{$label}: Accept header");
    $c->true(!\str_contains($req->url, $key), "{$label}: key never in the URL");

    if ($body === null) {
        $c->same(null, $req->body, "{$label}: no request body");
        $c->same(null, $req->headers['Content-Type'] ?? null, "{$label}: no Content-Type without a body");
    } else {
        $c->same('application/json', $req->headers['Content-Type'] ?? null, "{$label}: Content-Type");
        $c->same(
            \json_encode($body, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            $req->body,
            "{$label}: request body bytes",
        );
    }
};

// ═════ 1. GET sdk/community ══════════════════════════════════════════════════
$c->group('community.get');
$community = $gurb->community->get();
$wire($c, $rec, 'GET', "{$base}/api/sdk/community", null, 200, $COMMUNITY_KEY, 'GET sdk/community');
$c->same('cmt_demo', $community->id, 'community id decoded');
$c->same('demo', $community->slug, 'community slug decoded');
$c->same('PUBLIC', $community->type, 'visibility is `type` alone');
$c->true($community->isPublic(), 'isPublic derived, never a field');
$c->same(128, $community->memberCount, 'memberCount decoded as int');
$c->same(null, $community->logoUrl, 'null stays null, not ""');

// ═════ 2. Content lists — the { items, page, limit, total, hasMore } envelope ═
$c->group('content lists');
$tweets = $gurb->tweets->list(limit: 2);
$wire($c, $rec, 'GET', "{$base}/api/sdk/tweets?limit=2", null, 200, $COMMUNITY_KEY, 'GET sdk/tweets');
$c->same(2, \count($tweets), 'tweets page honours limit');
$c->same(1, $tweets->page, 'page');
$c->same(2, $tweets->limit, 'limit');
$c->same(5, $tweets->total, 'total');
$c->true($tweets->hasMore, 'hasMore comes from the server, not arithmetic');
$c->same('usr_1', $tweets->items[0]->author->userId, 'Author.userId is a User.id');
$c->same([], $tweets->items[0]->imageUrls, 'imageUrls is a list');
$c->same(null, $tweets->items[0]->editedAt, 'editedAt nullable');

$events = $gurb->events->list(page: 1, limit: 10, upcoming: true);
$wire($c, $rec, 'GET', "{$base}/api/sdk/events?page=1&limit=10&upcoming=true", null, 200, $COMMUNITY_KEY, 'GET sdk/events');
$c->same(2, \count($events), 'events decoded');
$c->same('2026-09-01T17:00:00.000Z', $events->items[0]->startsAt, 'startsAt is an ISO-8601 string');

$albums = $gurb->albums->list();
$wire($c, $rec, 'GET', "{$base}/api/sdk/albums", null, 200, $COMMUNITY_KEY, 'GET sdk/albums');
$c->same(24, $albums->items[0]->photoCount, 'album photoCount');

// blogs is the mock's plan-gated module: a designed 403.
$c->group('feature gate');
try {
    $gurb->blogs->list();
    $c->true(false, 'GET sdk/blogs should have thrown');
} catch (GurbApiException $e) {
    $wire($c, $rec, 'GET', "{$base}/api/sdk/blogs", null, 403, $COMMUNITY_KEY, 'GET sdk/blogs');
    $c->same(GurbErrorCode::FEATURE_NOT_AVAILABLE, $e->code(), 'declared code passes through');
    $c->same(403, $e->status(), 'status preserved');
    $c->same('req_demo_403', $e->requestId(), 'requestId surfaced');
    $c->true(!$e->isRetryable(), 'a plan gate is not retryable');
}

// ═════ 3. Members ════════════════════════════════════════════════════════════
$c->group('members');
$members = $gurb->members->list();
$wire($c, $rec, 'GET', "{$base}/api/sdk/members", null, 200, $COMMUNITY_KEY, 'GET sdk/members');
$c->same(3, $members->total, 'three seeded members');

$admins = $gurb->members->list(role: CommunityRole::Admin);
$wire($c, $rec, 'GET', "{$base}/api/sdk/members?role=ADMIN", null, 200, $COMMUNITY_KEY, 'GET sdk/members?role');
$c->same(1, $admins->total, 'role filter travels in the backend spelling');

$member = $gurb->members->get('mem_2');
$wire($c, $rec, 'GET', "{$base}/api/sdk/members/mem_2", null, 200, $COMMUNITY_KEY, 'GET sdk/members/{id}');
$c->same('mem_2', $member->memberId, 'memberId');
$c->same('usr_2', $member->userId, 'userId is separate from memberId');
$c->same('MEMBER', $member->role, 'role');

$promoted = $gurb->members->setRole('mem_2', CommunityRole::Moderator);
$wire($c, $rec, 'PATCH', "{$base}/api/sdk/members/mem_2/role", ['role' => 'MODERATOR'], 200, $COMMUNITY_KEY, 'PATCH sdk/members/{id}/role');
$c->same('MODERATOR', $promoted->role, 'role changed');

$perms = $gurb->members->getPermissions('mem_2');
$wire($c, $rec, 'GET', "{$base}/api/sdk/members/mem_2/permissions", null, 200, $COMMUNITY_KEY, 'GET sdk/members/{id}/permissions');
$c->same('mem_2', $perms->memberId, 'permissions memberId');
$c->same('MODERATOR', $perms->role, 'permissions role');
$c->same([], $perms->granted, 'no individual grants yet');
$c->same([], $perms->revoked, 'no individual revokes yet');
$c->true($perms->can(Permission::CREATE_POST), 'effective carries the role grant');

$updated = $gurb->members->updatePermissions(
    'mem_2',
    grant: [Permission::EDIT_ANY_EVENT],
    revoke: [Permission::CREATE_POST],
);
$wire(
    $c,
    $rec,
    'PATCH',
    "{$base}/api/sdk/members/mem_2/permissions",
    ['grant' => ['EDIT_ANY_EVENT'], 'revoke' => ['CREATE_POST']],
    200,
    $COMMUNITY_KEY,
    'PATCH sdk/members/{id}/permissions',
);
$c->same(['EDIT_ANY_EVENT'], $updated->granted, 'granted echoed back');
$c->same(['CREATE_POST'], $updated->revoked, 'revoked echoed back');
$c->true($updated->can(Permission::EDIT_ANY_EVENT), 'grant is effective');
$c->true(!$updated->can(Permission::CREATE_POST), 'revoke beats the role');

$gurb->members->remove('mem_3');
$wire($c, $rec, 'DELETE', "{$base}/api/sdk/members/mem_3", null, 200, $COMMUNITY_KEY, 'DELETE sdk/members/{id}');

// ═════ 4. Bulk membership ════════════════════════════════════════════════════
$c->group('bulk');
$result = $gurb->members->bulkUpsert([
    new BulkMemberInput('php-1', 'Sara', 'sara@example.com'),
    new BulkMemberInput('php-2', 'Omar', role: CommunityRole::Moderator),
    new BulkMemberInput('php-3', 'Bad', 'not-an-email'),
]);
$wire(
    $c,
    $rec,
    'POST',
    "{$base}/api/sdk/members/bulk",
    ['members' => [
        ['externalUserId' => 'php-1', 'displayName' => 'Sara', 'email' => 'sara@example.com'],
        ['externalUserId' => 'php-2', 'displayName' => 'Omar', 'role' => 'MODERATOR'],
        ['externalUserId' => 'php-3', 'displayName' => 'Bad', 'email' => 'not-an-email'],
    ]],
    200,
    $COMMUNITY_KEY,
    'POST sdk/members/bulk',
);
$c->same(3, $result->total, 'total is what the server received');
$c->same(2, \count($result->created), 'two rows created');
$c->same(0, \count($result->updated), 'nothing updated on a first run');
$c->same(1, \count($result->failed), 'the bad email failed alone');
$c->true($result->hasFailures(), 'hasFailures');
$c->same(2, $result->failed[0]->index, 'failure carries the index of the row you sent');
$c->same('php-3', $result->failed[0]->externalUserId, 'failure names the row');
$c->same(GurbErrorCode::VALIDATION_ERROR, $result->failed[0]->code, 'failure code is a stable GurbErrorCode');

// Re-sending is a sync, not a second import — the property the whole endpoint rests on.
$again = $gurb->members->bulkUpsert([new BulkMemberInput('php-1', 'Sara Renamed')]);
$c->same(0, \count($again->created), 'a known externalUserId is not duplicated');
$c->same(1, \count($again->updated), 'it is updated instead');

// ═════ 5. Embed ══════════════════════════════════════════════════════════════
$c->group('embed');
$session = $gurb->createEmbedSession('php-embed-1', 'زائر');
$wire(
    $c,
    $rec,
    'POST',
    "{$base}/api/embed/sessions",
    ['externalUserId' => 'php-embed-1', 'displayName' => 'زائر'],
    200,
    $COMMUNITY_KEY,
    'POST embed/sessions',
);
$c->true(\str_starts_with($session->token, 'et_'), 'a token came back');
$c->true($session->userId !== '', 'the external identity resolved to a Gurb user');
$c->true(!\str_contains($rec->last()['req']->body ?? '', 'role'), 'no role field on the wire — the host cannot elevate anyone');

$optional = $gurb->createEmbedSession('php-embed-2', 'Sara', 'sara@example.com', 'https://cdn.test/a.png');
$wire(
    $c,
    $rec,
    'POST',
    "{$base}/api/embed/sessions",
    ['externalUserId' => 'php-embed-2', 'displayName' => 'Sara', 'email' => 'sara@example.com', 'avatarUrl' => 'https://cdn.test/a.png'],
    200,
    $COMMUNITY_KEY,
    'POST embed/sessions (optional fields)',
);

$url = $gurb->buildEmbedUrl('demo', EmbedSection::Tweets, $session->token);
$c->same("{$base}/embed/demo/tweets#t={$session->token}", $url, 'iframe URL shape');
$c->true(!\str_contains(\explode('#', $url)[0], $session->token), 'the token is in the fragment, not the query string');

// The framed page must actually exist at that path, and must never see the token.
$framed = (new CurlHttpClient())->send(new HttpRequest('GET', \explode('#', $url)[0], [], null, 5000));
$c->same(200, $framed->status, 'GET /embed/{slug}/{section} serves the frame');
$c->true(!\str_contains($framed->body, $session->token), 'the server never received the fragment');

// ═════ 6. Community creation by approval ═════════════════════════════════════
$c->group('community requests');
$suffix = \bin2hex(\random_bytes(3));
$pending = $gurb->requestCommunityCreation(new CreateCommunityInput(
    name: 'نادي القراءة',
    slug: "php-pending-{$suffix}",
    description: 'وصف',
));
$wire(
    $c,
    $rec,
    'POST',
    "{$base}/api/sdk/community-requests",
    ['name' => 'نادي القراءة', 'slug' => "php-pending-{$suffix}", 'description' => 'وصف'],
    201,
    $COMMUNITY_KEY,
    'POST sdk/community-requests',
);
$c->true($pending->isPending(), 'a community key gets a PENDING request, never a Community');
$c->same(null, $pending->communityId, 'communityId is null until approved');
$c->same(null, $pending->decidedAt, 'decidedAt is null while pending');
$c->same(null, $pending->rejectionReason, 'rejectionReason is null while pending');

$fetched = $gurb->communityRequests->get($pending->id);
$wire($c, $rec, 'GET', "{$base}/api/sdk/community-requests/{$pending->id}", null, 200, $COMMUNITY_KEY, 'GET sdk/community-requests/{id}');
$c->same($pending->id, $fetched->id, 'round-trips by id');

$queue = $gurb->communityRequests->list(CommunityRequestStatus::Pending);
$wire($c, $rec, 'GET', "{$base}/api/sdk/community-requests?status=PENDING", null, 200, $COMMUNITY_KEY, 'GET sdk/community-requests?status');
$c->true($queue->total >= 1, 'the filed request is in the queue');

// Optional fields absent means absent, not null.
$bare = $gurb->requestCommunityCreation(new CreateCommunityInput(name: 'Bare', slug: "php-bare-{$suffix}"));
$wire(
    $c,
    $rec,
    'POST',
    "{$base}/api/sdk/community-requests",
    ['name' => 'Bare', 'slug' => "php-bare-{$suffix}"],
    201,
    $COMMUNITY_KEY,
    'POST sdk/community-requests (no optionals)',
);

// A taken slug is an ordinary VALIDATION_ERROR, from the server this time.
try {
    $gurb->requestCommunityCreation(new CreateCommunityInput(name: 'Dupe', slug: 'demo'));
    $c->true(false, 'a taken slug should have thrown');
} catch (GurbApiException $e) {
    $c->same(400, $e->status(), 'taken slug is a 400');
    $c->same(GurbErrorCode::VALIDATION_ERROR, $e->code(), 'taken slug maps to VALIDATION_ERROR');
}

// ═════ 7. Admin: communities ═════════════════════════════════════════════════
$c->group('admin communities');
$all = $admin->communities->list(limit: 5);
$wire($c, $adminRec, 'GET', "{$base}/api/admin/sdk/communities?limit=5", null, 200, $ADMIN_KEY, 'GET admin/sdk/communities');
$c->true($all->total >= 1, 'the admin key lists across tenants');

$searched = $admin->communities->list(search: 'demo');
$wire($c, $adminRec, 'GET', "{$base}/api/admin/sdk/communities?search=demo", null, 200, $ADMIN_KEY, 'GET admin/sdk/communities?search');

$created = $admin->communities->create(new CreateCommunityInput(
    name: 'مجتمع فوري',
    slug: "php-admin-{$suffix}",
    type: 'PUBLIC',
    ownerUserId: 'usr_owner',
));
$wire(
    $c,
    $adminRec,
    'POST',
    "{$base}/api/admin/sdk/communities",
    ['name' => 'مجتمع فوري', 'slug' => "php-admin-{$suffix}", 'type' => 'PUBLIC', 'ownerUserId' => 'usr_owner'],
    201,
    $ADMIN_KEY,
    'POST admin/sdk/communities',
);
$c->same('PUBLIC', $created->type, 'admin create returns a Community, not a request');
$c->true($created->id !== '', 'the community exists immediately');
$c->true(!\str_contains($adminRec->last()['req']->body ?? '', '\\u'), 'Arabic survives unescaped in the body');

// ═════ 8. Admin: the approval queue ══════════════════════════════════════════
$c->group('admin requests');
$adminQueue = $admin->communityRequests->list(CommunityRequestStatus::Pending, page: 1, limit: 50);
$wire($c, $adminRec, 'GET', "{$base}/api/admin/sdk/community-requests?status=PENDING&page=1&limit=50", null, 200, $ADMIN_KEY, 'GET admin/sdk/community-requests');
$c->true($adminQueue->total >= 2, 'both filed requests are visible to the admin');

$seen = $admin->communityRequests->get($pending->id);
$wire($c, $adminRec, 'GET', "{$base}/api/admin/sdk/community-requests/{$pending->id}", null, 200, $ADMIN_KEY, 'GET admin/sdk/community-requests/{id}');
$c->same($pending->id, $seen->id, 'admin reads the same request');

$approved = $admin->communityRequests->approve($pending->id);
$wire($c, $adminRec, 'POST', "{$base}/api/admin/sdk/community-requests/{$pending->id}/approve", null, 200, $ADMIN_KEY, 'POST .../approve');
$c->true($approved->isApproved(), 'status flipped to APPROVED');
$c->true($approved->communityId !== null, 'approve names the community it became');
$c->true($approved->decidedAt !== null, 'decidedAt is set');
$c->true($approved->decidedByUserId !== null, 'decidedByUserId is set');

// The decision is what is idempotent, not the click.
try {
    $admin->communityRequests->approve($pending->id);
    $c->true(false, 'a second approve should have thrown');
} catch (GurbApiException $e) {
    $c->same(400, $e->status(), 'approving twice is refused');
    $c->same(GurbErrorCode::VALIDATION_ERROR, $e->code(), 'and maps to VALIDATION_ERROR');
}

$rejected = $admin->communityRequests->reject($bare->id, '   Slug is too generic   ');
$wire(
    $c,
    $adminRec,
    'POST',
    "{$base}/api/admin/sdk/community-requests/{$bare->id}/reject",
    ['reason' => 'Slug is too generic'],
    200,
    $ADMIN_KEY,
    'POST .../reject',
);
$c->true($rejected->isRejected(), 'status flipped to REJECTED');
$c->same('Slug is too generic', $rejected->rejectionReason, 'the reason is trimmed before it is sent');
$c->same(null, $rejected->communityId, 'a rejected request became no community');

// ═════ 9. Admin: API keys ════════════════════════════════════════════════════
$c->group('admin api keys');
$minted = $admin->apiKeys->create('cmt_demo', 'php-contract');
$wire(
    $c,
    $adminRec,
    'POST',
    "{$base}/api/admin/sdk/api-keys",
    ['communityId' => 'cmt_demo', 'name' => 'php-contract', 'scopes' => ['read']],
    201,
    $ADMIN_KEY,
    'POST admin/sdk/api-keys',
);
$c->true($minted instanceof ApiKeyWithSecret, 'create returns the one response carrying a secret');
$c->same(['read'], $minted->scopes, 'a minted key defaults to read-only');
$c->true(\str_starts_with($minted->key, 'gurb_'), 'the plaintext key is present exactly once');
$c->same(null, $minted->revokedAt, 'a fresh key is not revoked');
$c->true(!$minted->isRevoked(), 'isRevoked');

$writeKey = $admin->apiKeys->create('cmt_demo', 'php-writer', [ApiKeyScope::Read, ApiKeyScope::Write]);
$wire(
    $c,
    $adminRec,
    'POST',
    "{$base}/api/admin/sdk/api-keys",
    ['communityId' => 'cmt_demo', 'name' => 'php-writer', 'scopes' => ['read', 'write']],
    201,
    $ADMIN_KEY,
    'POST admin/sdk/api-keys (write scope)',
);
$c->same(['read', 'write'], $writeKey->scopes, 'write has to be asked for explicitly');

$keys = $admin->apiKeys->list(communityId: 'cmt_demo');
$wire($c, $adminRec, 'GET', "{$base}/api/admin/sdk/api-keys?communityId=cmt_demo", null, 200, $ADMIN_KEY, 'GET admin/sdk/api-keys');
$c->true($keys->total >= 2, 'both keys are listed');
foreach ($keys as $summary) {
    $c->true(!($summary instanceof ApiKeyWithSecret), 'list never yields a usable secret');
    $c->true(!\property_exists($summary, 'key'), 'a summary has no `key` property at all');
}

$revoked = $admin->apiKeys->revoke($minted->id);
$wire($c, $adminRec, 'POST', "{$base}/api/admin/sdk/api-keys/{$minted->id}/revoke", null, 200, $ADMIN_KEY, 'POST admin/sdk/api-keys/{id}/revoke');
$c->true($revoked->isRevoked(), 'revocation is reported');
$c->true($revoked->revokedAt !== null, 'revokedAt is set');

// ═════ 10. Credential separation, enforced by the server ═════════════════════
$c->group('credential separation');
$probe = new Recording();
$probeResponse = $probe->send(new HttpRequest(
    'GET',
    "{$base}/api/admin/sdk/communities",
    ['X-Api-Key' => $COMMUNITY_KEY, 'Accept' => 'application/json'],
    null,
    5000,
));
$c->same(403, $probeResponse->status, 'a community key on an admin route is refused');
$mapped = GurbApiException::fromResponse($probeResponse->status, \json_decode($probeResponse->body, true));
$c->same(GurbErrorCode::FORBIDDEN_SURFACE, $mapped->code(), 'and the SDK maps that to FORBIDDEN_SURFACE');

$probeResponse = $probe->send(new HttpRequest(
    'GET',
    "{$base}/api/sdk/community",
    ['X-Api-Key' => $ADMIN_KEY, 'Accept' => 'application/json'],
    null,
    5000,
));
$c->same(403, $probeResponse->status, 'an admin key on a community route is refused');
$mapped = GurbApiException::fromResponse($probeResponse->status, \json_decode($probeResponse->body, true));
$c->same(GurbErrorCode::FORBIDDEN_SURFACE, $mapped->code(), 'FORBIDDEN_SURFACE again');

$probeResponse = $probe->send(new HttpRequest(
    'GET',
    "{$base}/api/sdk/community",
    ['X-Api-Key' => 'not-a-key', 'Accept' => 'application/json'],
    null,
    5000,
));
$c->same(401, $probeResponse->status, 'a malformed key is a 401');
$mapped = GurbApiException::fromResponse($probeResponse->status, \json_decode($probeResponse->body, true));
$c->same(GurbErrorCode::INVALID_API_KEY, $mapped->code(), 'INVALID_API_KEY');

$probeResponse = $probe->send(new HttpRequest(
    'GET',
    "{$base}/api/sdk/nope",
    ['X-Api-Key' => $COMMUNITY_KEY, 'Accept' => 'application/json'],
    null,
    5000,
));
$c->same(404, $probeResponse->status, 'an unknown community route is a 404');
$mapped = GurbApiException::fromResponse($probeResponse->status, \json_decode($probeResponse->body, true));
$c->same(GurbErrorCode::NOT_FOUND, $mapped->code(), 'NOT_FOUND');

// ═════ 11. Local guards: nothing may reach the network ═══════════════════════
$c->group('local guards');
$guard = static function (Checks $c, callable $fn, string $needle, string $label): void {
    $before = null;
    try {
        $before = $fn();
        $c->true(false, "{$label}: should have thrown");
    } catch (GurbApiException $e) {
        $c->same(GurbErrorCode::VALIDATION_ERROR, $e->code(), "{$label}: code");
        $c->same(0, $e->status(), "{$label}: status 0 — no server ever saw it");
        $c->true(!$e->isRetryable(), "{$label}: not retryable");
        $c->true(\str_contains($e->getMessage(), $needle), "{$label}: message names the mistake");
    }
    unset($before);
};

$before = \count($rec->calls);
$guard($c, static fn () => $gurb->members->updatePermissions('mem_1', ['CREATE_POST'], ['CREATE_POST']), 'grant and revoke the same permission', 'grant/revoke conflict');
$guard($c, static fn () => $gurb->members->updatePermissions('mem_1'), 'at least one permission', 'empty change set');
$guard($c, static fn () => $gurb->members->bulkUpsert([]), 'at least one member', 'empty bulk');
$guard($c, static fn () => $gurb->members->bulkUpsert(\array_map(
    static fn (int $i): BulkMemberInput => new BulkMemberInput("u{$i}", "User {$i}"),
    \range(1, MembersResource::BULK_MEMBER_LIMIT + 1),
)), 'at most 500', 'bulk over the limit');
$guard($c, static fn () => $gurb->members->bulkUpsert([
    new BulkMemberInput('dup', 'A'),
    new BulkMemberInput('dup', 'B'),
]), 'Duplicate externalUserId in the same batch: dup', 'duplicate id in one batch');
$guard($c, static fn () => $admin->communityRequests->reject('creq_1', '   '), 'rejection reason is required', 'rejection with no reason');
$c->same($before, \count($rec->calls), 'not one local guard reached the network');

// Key shapes reject each other at construction, before any socket is opened.
$c->group('key shapes');
try {
    new GurbClient($ADMIN_KEY, $base);
    $c->true(false, 'an admin key in GurbClient should have thrown');
} catch (GurbApiException $e) {
    $c->same(GurbErrorCode::INVALID_API_KEY, $e->code(), 'admin key in GurbClient: code');
    $c->same(0, $e->status(), 'admin key in GurbClient: status 0');
    $c->true(\str_contains($e->getMessage(), 'super-admin key, not a community API key'), 'admin key in GurbClient: message');
}
try {
    new GurbAdminClient($COMMUNITY_KEY, $base);
    $c->true(false, 'a community key in GurbAdminClient should have thrown');
} catch (GurbApiException $e) {
    $c->true(\str_contains($e->getMessage(), 'community API key, not a super-admin key'), 'community key in GurbAdminClient: message');
}
try {
    new GurbClient('eyJhbGciOiJIUzI1NiJ9.a.b', $base);
    $c->true(false, 'a JWT should have thrown');
} catch (GurbApiException $e) {
    $c->true(\str_contains($e->getMessage(), 'session JWT, not an API key'), 'JWT in GurbClient: message');
}

exit($c->report());
