<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\ApiKeyScope;
use Gurb\CommunityRequestStatus;
use Gurb\GurbAdminClient;
use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\GurbErrorCode;
use Gurb\Input\CreateCommunityInput;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdminClientTest extends TestCase
{
    private const COMMUNITY_KEY = ApiKeyTest::KEY;

    /** Same shape, plus the `sa_` segment that changes everything. */
    public const ADMIN_KEY = 'gurb_sa_a1b2c3d4e5f6_' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    // ─── Credential separation ───────────────────────────────────────────────
    //
    // These are the tests that would fail first if someone "simplified" the two
    // key patterns into one. They are worth more than every other test in this
    // file: the rest check that requests are shaped right, these check that the
    // wrong credential cannot get anywhere near them.

    #[Test]
    public function it_refuses_a_super_admin_key_in_the_community_client_and_says_why(): void
    {
        $this->expectException(GurbApiException::class);
        $this->expectExceptionMessage('super-admin key, not a community API key');

        // No transport passed: if this were deferred to the first request, the
        // line would succeed and an app would be holding platform authority.
        new GurbClient(self::ADMIN_KEY);
    }

    #[Test]
    public function it_refuses_a_community_key_in_the_admin_client_and_says_why(): void
    {
        $this->expectException(GurbApiException::class);
        $this->expectExceptionMessage('community API key, not a super-admin key');

        new GurbAdminClient(self::COMMUNITY_KEY);
    }

    #[Test]
    public function each_key_is_accepted_by_its_own_client(): void
    {
        $community = new GurbClient(self::COMMUNITY_KEY, httpClient: StubHttpClient::json(200, []));
        $admin = new GurbAdminClient(self::ADMIN_KEY, httpClient: StubHttpClient::json(200, []));

        self::assertInstanceOf(GurbClient::class, $community);
        self::assertInstanceOf(GurbAdminClient::class, $admin);
    }

    #[Test]
    public function the_two_key_shapes_are_mutually_exclusive_not_merely_checked_in_order(): void
    {
        // The admin key cannot satisfy the community pattern because `sa` is not
        // twelve hex characters. Proving it from both directions means the
        // separation survives someone reordering the checks in ApiKey.
        foreach ([[self::ADMIN_KEY, GurbClient::class], [self::COMMUNITY_KEY, GurbAdminClient::class]] as [$key, $class]) {
            try {
                new $class($key);
                self::fail("{$class} accepted the other credential.");
            } catch (GurbApiException $e) {
                self::assertSame(GurbErrorCode::INVALID_API_KEY, $e->code());
                self::assertSame(0, $e->status(), 'Nothing was sent, so there is no HTTP status.');
                self::assertFalse($e->isRetryable(), 'Retrying with the wrong kind of key never helps.');
            }
        }
    }

    #[Test]
    public function it_rejects_a_jwt_in_the_admin_client_with_an_admin_specific_message(): void
    {
        $this->expectException(GurbApiException::class);
        // Not the generic API-key message: someone building an admin tool needs
        // to be told the admin format, not the community one.
        $this->expectExceptionMessage('gurb_sa_<id>_<secret>');

        new GurbAdminClient('eyJhbGciOiJIUzI1NiJ9.abc.def');
    }

    #[Test]
    public function an_empty_admin_key_names_the_missing_argument(): void
    {
        $this->expectException(GurbApiException::class);
        $this->expectExceptionMessage('A Gurb super-admin key is required');

        new GurbAdminClient('');
    }

    #[Test]
    public function a_malformed_admin_key_names_the_admin_format(): void
    {
        $this->expectException(GurbApiException::class);
        $this->expectExceptionMessage('Malformed Gurb super-admin key');

        new GurbAdminClient('gurb_sa_nothex_short');
    }

    // ─── Admin routes ────────────────────────────────────────────────────────

    #[Test]
    public function it_creates_a_community_under_the_admin_path_with_the_admin_key(): void
    {
        $http = StubHttpClient::json(201, ['data' => ['id' => 'cmt_1', 'slug' => 'new', 'type' => 'PRIVATE']]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $community = $admin->communities->create(new CreateCommunityInput(name: 'جديد', slug: 'new'));

        self::assertSame('https://x.test/api/admin/sdk/communities', $http->lastCall()->url);
        self::assertSame('POST', $http->lastCall()->method);
        // The admin key, on the admin path. The shared Requester puts it in the
        // same header for both clients — that is the point of sharing it.
        self::assertSame(self::ADMIN_KEY, $http->lastCall()->headers['X-Api-Key']);
        self::assertStringNotContainsString(self::ADMIN_KEY, $http->lastCall()->url);
        self::assertSame('cmt_1', $community->id);
    }

    #[Test]
    public function community_routes_and_admin_routes_do_not_share_a_prefix(): void
    {
        $adminHttp = StubHttpClient::json(200, ['data' => ['items' => []]]);
        (new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $adminHttp))->communityRequests->list();

        $communityHttp = StubHttpClient::json(200, ['data' => ['items' => []]]);
        (new GurbClient(self::COMMUNITY_KEY, 'https://x.test', $communityHttp))->communityRequests->list();

        // Same resource name, two different surfaces. The backend routes purely
        // on the prefix, so getting this wrong would send a community key to an
        // admin route and produce a 403 nobody could explain.
        self::assertSame('https://x.test/api/admin/sdk/community-requests', $adminHttp->lastCall()->url);
        self::assertSame('https://x.test/api/sdk/community-requests', $communityHttp->lastCall()->url);
    }

    #[Test]
    public function a_community_creation_input_omits_absent_optional_fields(): void
    {
        $http = StubHttpClient::json(201, ['data' => []]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $admin->communities->create(new CreateCommunityInput(name: 'نادي', slug: 'club', type: 'PRIVATE'));

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['name' => 'نادي', 'slug' => 'club', 'type' => 'PRIVATE'], $body);
        // Absent, not null: the backend's optional-field validation treats
        // "explicitly null" as a value it has to reject.
        self::assertArrayNotHasKey('description', $body);
        self::assertArrayNotHasKey('ownerUserId', $body);
    }

    #[Test]
    public function arabic_names_survive_json_encoding_unescaped(): void
    {
        $http = StubHttpClient::json(201, ['data' => []]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $admin->communities->create(new CreateCommunityInput(name: 'مجتمع المتجر', slug: 'shop'));

        // JSON_UNESCAPED_UNICODE in the Requester. Not cosmetic: \u-escaped
        // Arabic is three times the bytes and unreadable in a request log, and
        // this platform's communities are Arabic by default.
        self::assertStringContainsString('مجتمع المتجر', (string) $http->lastCall()->body);
    }

    // ─── API keys ────────────────────────────────────────────────────────────

    #[Test]
    public function a_minted_key_defaults_to_read_only_scope(): void
    {
        $http = StubHttpClient::json(201, ['data' => ['id' => 'k1', 'key' => 'gurb_k1_secret', 'scopes' => ['read']]]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $admin->apiKeys->create('cmt_1', 'zapier');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        // Most integrations only read. A read-only key that leaks is an incident
        // you can close; asking for write should be a deliberate keystroke.
        self::assertSame(['read'], $body['scopes']);
        self::assertSame('https://x.test/api/admin/sdk/api-keys', $http->lastCall()->url);
    }

    #[Test]
    public function write_scope_has_to_be_asked_for_explicitly(): void
    {
        $http = StubHttpClient::json(201, ['data' => []]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $admin->apiKeys->create('cmt_1', 'importer', [ApiKeyScope::Read, ApiKeyScope::Write]);

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['read', 'write'], $body['scopes']);
    }

    #[Test]
    public function the_plaintext_key_comes_back_once_and_carries_its_summary(): void
    {
        $http = StubHttpClient::json(201, ['data' => [
            'id' => 'a1b2c3d4e5f6',
            'communityId' => 'cmt_1',
            'name' => 'zapier',
            'scopes' => ['read'],
            'lastUsedAt' => null,
            'revokedAt' => null,
            'createdAt' => '2026-08-13T10:00:00Z',
            'key' => 'gurb_a1b2c3d4e5f6_thesecret',
        ]]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $minted = $admin->apiKeys->create('cmt_1', 'zapier');

        self::assertSame('gurb_a1b2c3d4e5f6_thesecret', $minted->key);
        // Inheritance, not composition: a freshly minted key is usable anywhere
        // a summary is, without reaching through a `->summary` property.
        self::assertInstanceOf(\Gurb\Model\ApiKeySummary::class, $minted);
        self::assertSame('zapier', $minted->name);
        self::assertFalse($minted->isRevoked());
    }

    #[Test]
    public function listing_keys_never_yields_a_usable_secret(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['items' => [
            ['id' => 'k1', 'communityId' => 'cmt_1', 'name' => 'zapier', 'scopes' => ['read'], 'revokedAt' => null],
        ]]]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $page = $admin->apiKeys->list(communityId: 'cmt_1');

        self::assertSame('https://x.test/api/admin/sdk/api-keys?communityId=cmt_1', $http->lastCall()->url);
        // There is no read-back route, so there is deliberately no `$key`
        // property on a summary to read one out of.
        self::assertFalse(\property_exists($page->items[0], 'key'));
    }

    #[Test]
    public function revoking_posts_to_the_key_and_reports_it_revoked(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['id' => 'k1', 'revokedAt' => '2026-08-13T11:00:00Z']]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $summary = $admin->apiKeys->revoke('k1');

        self::assertSame('https://x.test/api/admin/sdk/api-keys/k1/revoke', $http->lastCall()->url);
        self::assertSame('POST', $http->lastCall()->method);
        // No body, so no Content-Type: the id in the URL is the whole request.
        self::assertNull($http->lastCall()->body);
        self::assertArrayNotHasKey('Content-Type', $http->lastCall()->headers);
        self::assertTrue($summary->isRevoked());
    }

    // ─── The approval queue ──────────────────────────────────────────────────

    #[Test]
    public function it_refuses_to_reject_a_request_without_a_reason_before_making_a_request(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        try {
            $admin->communityRequests->reject('creq_1', '   ');
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::VALIDATION_ERROR, $e->code());
            self::assertSame(0, $e->status());
            self::assertStringContainsString('rejection reason is required', $e->getMessage());
        }

        // The assertion that matters: whitespace is not a reason, and the
        // requester who gets shown it would have been shown nothing.
        self::assertCount(0, $http->calls, 'No HTTP call should have been made.');
    }

    #[Test]
    public function a_rejection_reason_is_trimmed_before_it_is_sent(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['status' => 'REJECTED', 'rejectionReason' => 'Slug too generic']]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $request = $admin->communityRequests->reject('creq_1', '  Slug too generic  ');

        self::assertSame('https://x.test/api/admin/sdk/community-requests/creq_1/reject', $http->lastCall()->url);
        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['reason' => 'Slug too generic'], $body);
        self::assertTrue($request->isRejected());
        self::assertSame('Slug too generic', $request->rejectionReason);
    }

    #[Test]
    public function approving_carries_no_body_and_returns_the_new_community_id(): void
    {
        $http = StubHttpClient::json(200, ['data' => [
            'id' => 'creq_1',
            'status' => 'APPROVED',
            'communityId' => 'cmt_new',
            'decidedByUserId' => 'usr_superadmin',
        ]]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $request = $admin->communityRequests->approve('creq_1');

        self::assertSame('https://x.test/api/admin/sdk/community-requests/creq_1/approve', $http->lastCall()->url);
        self::assertNull($http->lastCall()->body);
        self::assertTrue($request->isApproved());
        self::assertSame('cmt_new', $request->communityId);
    }

    #[Test]
    public function a_status_filter_travels_as_the_backends_uppercase_spelling(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['items' => []]]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $admin->communityRequests->list(CommunityRequestStatus::Pending, limit: 50);

        // The enum's *value* is what crosses the wire, never its case name. The
        // backend compares uppercase strings.
        self::assertSame(
            'https://x.test/api/admin/sdk/community-requests?status=PENDING&limit=50',
            $http->lastCall()->url,
        );
    }

    #[Test]
    public function a_request_id_that_could_break_out_of_the_path_is_escaped(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        $admin->communityRequests->get('../api-keys');

        // Without rawurlencode this would walk up the path and hit a different
        // admin route entirely — with a valid admin key attached.
        self::assertSame(
            'https://x.test/api/admin/sdk/community-requests/..%2Fapi-keys',
            $http->lastCall()->url,
        );
    }

    #[Test]
    public function a_server_side_403_on_an_admin_route_is_an_ordinary_gurb_exception(): void
    {
        // Belt and braces behind the constructor check: even if a community key
        // somehow reached an admin route, the failure is the same shape as every
        // other failure and carries the server's explanation.
        $http = StubHttpClient::json(403, [
            'success' => false,
            'error' => 'FORBIDDEN_SURFACE',
            'message' => 'A community API key cannot perform platform administration',
        ]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);

        try {
            $admin->communities->list();
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::FORBIDDEN_SURFACE, $e->code());
            self::assertSame(403, $e->status());
            self::assertFalse($e->isRetryable());
        }
    }

    #[Test]
    public function the_admin_client_shares_the_community_clients_transport_behaviour(): void
    {
        // One Requester, two clients. If the admin path had its own copy of the
        // HTTP logic, this is where the drift would show up first — a different
        // timeout message, a different error code, a key in a query string.
        $admin = new GurbAdminClient(
            self::ADMIN_KEY,
            'https://x.test',
            StubHttpClient::failing('timed out', timedOut: true),
            timeoutMs: 2500,
        );

        try {
            $admin->communities->list();
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::NETWORK_ERROR, $e->code());
            self::assertSame(0, $e->status());
            self::assertStringContainsString('timed out after 2500ms', $e->getMessage());
            self::assertTrue($e->isRetryable());
        }
    }

    #[Test]
    public function it_appends_api_itself_so_the_admin_base_url_stays_an_origin(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['items' => []]]);
        $admin = new GurbAdminClient(self::ADMIN_KEY, 'https://x.test///', $http);

        $admin->communities->list();

        self::assertSame('https://x.test/api/admin/sdk/communities', $http->lastCall()->url);
    }
}
