<?php

declare(strict_types=1);

namespace Gurb\Resource\Admin;

use Gurb\ApiKeyScope;
use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\ApiKeySummary;
use Gurb\Model\ApiKeyWithSecret;
use Gurb\Model\Paginated;

/**
 * Mint, list and revoke the community keys other integrations run on.
 *
 * This resource is why the super-admin key must never be the same object as a
 * community key: a credential that can mint credentials is a credential that
 * can grant itself anything, indefinitely, without anyone re-approving it.
 */
final class AdminApiKeysResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * Never returns a usable key — only summaries. There is no read-back route
     * anywhere in this SDK or on the platform, so `list()` cannot be used to
     * recover a secret somebody lost.
     *
     * @return Paginated<ApiKeySummary>
     *
     * @throws GurbApiException
     */
    public function list(?string $communityId = null, ?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'admin/sdk/api-keys', [
                'communityId' => $communityId,
                'page' => $page,
                'limit' => $limit,
            ]),
            ApiKeySummary::fromArray(...),
        );
    }

    /**
     * Mint a community-scoped key.
     *
     * THE PLAINTEXT KEY IS IN THIS RESPONSE AND NOWHERE ELSE, EVER. Hand it to
     * its owner now or discard it and mint another; if the secret could be
     * fetched again, then read access to your admin surface would be equivalent
     * to holding every key it ever issued.
     *
     * Scopes default to read-only, which is the right default because most
     * integrations only read and a read-only key that leaks is an incident you
     * can close. Asking for write is one extra argument, and it should feel like
     * a decision.
     *
     * @param string             $name   Shown in the dashboard so a human can tell
     *                                   keys apart later — and, more importantly,
     *                                   so a human can tell which one to revoke at
     *                                   3am. "zapier-prod", not "key 4".
     * @param list<ApiKeyScope>  $scopes
     *
     * @throws GurbApiException NOT_FOUND if the community does not exist.
     */
    public function create(
        string $communityId,
        string $name,
        array $scopes = [ApiKeyScope::Read],
    ): ApiKeyWithSecret {
        return ApiKeyWithSecret::fromArray($this->requester->request(
            'POST',
            'admin/sdk/api-keys',
            body: [
                'communityId' => $communityId,
                'name' => $name,
                // array_values because array_map over a list stays a list, but a
                // caller who filtered their scopes upstream would hand us gapped
                // keys — and a JSON object where the server expects an array.
                'scopes' => \array_values(\array_map(
                    static fn (ApiKeyScope $scope): string => $scope->value,
                    $scopes,
                )),
            ],
        ));
    }

    /**
     * Revoke a key.
     *
     * Takes effect immediately and CANNOT BE UNDONE. Revocation is one-way by
     * design so that "is this key still live?" has exactly one answer — an
     * un-revoke would mean the answer depends on when you asked, which is not
     * something you want to reason about during an incident.
     *
     * @param string $keyId The key id — `ApiKeySummary::$id`, the `<12 hex>` in
     *                      the middle of the key. Not the secret; you do not have
     *                      the secret and do not need it to revoke.
     *
     * @throws GurbApiException
     */
    public function revoke(string $keyId): ApiKeySummary
    {
        return ApiKeySummary::fromArray($this->requester->request(
            'POST',
            'admin/sdk/api-keys/' . \rawurlencode($keyId) . '/revoke',
        ));
    }
}
