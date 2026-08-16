<?php

declare(strict_types=1);

namespace Gurb;

use Gurb\Http\HttpClient;
use Gurb\Internal\Requester;
use Gurb\Model\SdkIdentity;
use Gurb\Resource\Admin\AdminApiKeysResource;
use Gurb\Resource\Admin\AdminCommunitiesResource;
use Gurb\Resource\Admin\AdminCommunityRequestsResource;

/**
 * Platform administration. A SEPARATE CLASS FROM GurbClient, on purpose.
 *
 * Everything reachable from here is an operation an ordinary integration must
 * never perform: creating communities, approving requests, and minting and
 * revoking the keys other integrations run on. Keeping it in its own class with
 * its own credential type means the dangerous surface is something you have to
 * reach for deliberately — you cannot arrive at `communities->create()` by
 * autocompleting from a client you built to render a feed.
 *
 * That is not a hypothetical. The failure mode this prevents is ordinary: a
 * developer needs one admin operation for a migration script, passes the admin
 * key to the client the app already builds, and now the request handler that
 * renders a tweet list is holding a credential that can mint keys for every
 * community on the platform. Two classes make that a compile error at the line
 * where the key is passed, not a finding in an audit six months later.
 *
 * ⚠️  This object holds a credential whose blast radius is the entire platform.
 * Injected from a secret store, one holder, never in a repository, rotated when
 * staff change. If you are unsure whether a script needs it, it does not.
 *
 * The split mirrors how the platform itself works: SUPER_ADMIN is not a bigger
 * community role, it is a different kind of actor — which is also why
 * `CommunityRole` has no case for it.
 */
final class GurbAdminClient
{
    private const DEFAULT_BASE_URL = 'https://mygurb.com';
    private const DEFAULT_TIMEOUT_MS = 15_000;

    private readonly Requester $requester;

    public readonly AdminCommunitiesResource $communities;
    public readonly AdminCommunityRequestsResource $communityRequests;
    public readonly AdminApiKeysResource $apiKeys;

    /**
     * @param string $adminKey `gurb_sa_<id>_<secret>`. A community key here is
     *                         rejected at construction with a message naming the
     *                         mistake — see ApiKey::assertValidAdmin().
     * @param string $baseUrl  Origin only — NO `/api` suffix, same rule as
     *                         GurbClient. The requester appends `api/` itself.
     * @param HttpClient|null $httpClient Inject to reuse your app's HTTP stack,
     *                                    or a stub in tests. Defaults to curl.
     * @param int $timeoutMs Whole-request budget. Default 15s.
     *
     * @throws GurbApiException INVALID_API_KEY if the key is not a super-admin key.
     */
    public function __construct(
        string $adminKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?HttpClient $httpClient = null,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ) {
        // Checked at construction, before anything is sent. A community key
        // passed here would otherwise work perfectly until the first admin call
        // returned 403 — and a 403 reads as "my key is wrong", which sends you
        // to rotate a credential that was fine all along.
        ApiKey::assertValidAdmin($adminKey);

        // THE SAME Requester GurbClient USES. Not a copy: the header name, the
        // error mapping, the timeout handling and the envelope unwrapping are
        // shared, so the admin surface cannot quietly drift into, say, putting
        // the key somewhere a log will find it. A difference between the two
        // paths there would be a security difference, not a style difference.
        $this->requester = new Requester(
            $adminKey,
            \rtrim($baseUrl, '/'),
            $httpClient,
            $timeoutMs,
        );

        $this->communities = new AdminCommunitiesResource($this->requester);
        $this->communityRequests = new AdminCommunityRequestsResource($this->requester);
        $this->apiKeys = new AdminApiKeysResource($this->requester);
    }

    /**
     * DELIBERATELY ABSENT FROM THIS CLASS: everything a community key does.
     *
     * There is no `tweets`, no `members`, no `createEmbedSession()`. A
     * super-admin key has no community context — it is not a member of anything
     * — so those calls have no meaning here and the server refuses them with a
     * 403 saying so. If an admin tool needs to read a community's feed, mint a
     * community key for that community and build a GurbClient with it. The extra
     * step is the audit trail.
     */

    /**
     * WHO THIS KEY ACTS FOR. Make this your first call.
     *
     * It answers the one question you cannot answer from the key string itself:
     * was the key you were handed actually set up FOR you, or does it still act
     * for the platform admin who minted it?
     *
     * That matters before anything irreversible. A platform key can only be
     * minted BY a super admin, so a key with no subject founds every community
     * in the admin's name — the customer ends up owning nothing, and nobody
     * notices until someone asks why their community belongs to someone else.
     *
     * ```php
     * $me = $admin->me();
     * if ($me->actsForIssuer) {
     *     throw new RuntimeException(
     *         'This key has no subject — anything I create will belong to the platform admin.'
     *     );
     * }
     * $owner = $me->effectiveOwner();   // email, display name, phone
     * ```
     *
     * `effectiveOwner()->phone` is never null: an account with no number reports
     * a run of zeros with `phoneIsPlaceholder` set, so a profile card has a field
     * of the right shape to render. Use `realPhone()` when you need the truth.
     *
     * A 200 proves the key is live and names a real, active account. It proves
     * nothing about permissions — those are checked per operation and differ per
     * section by design.
     *
     * @throws GurbApiException
     */
    public function me(): SdkIdentity
    {
        return SdkIdentity::fromArray($this->requester->request('GET', 'admin/sdk/me'));
    }
}
