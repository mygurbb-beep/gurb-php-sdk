<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * The only response that ever carries a usable key.
 *
 * `$key` is shown EXACTLY ONCE, in the reply to `apiKeys->create()`, and exists
 * nowhere else afterwards — not in `apiKeys->list()`, not in the dashboard, not
 * in the database in a readable form. There is no read-back route by design: if
 * the secret could be fetched again, read access to your admin surface would be
 * equivalent to holding every key it ever issued.
 *
 * So: hand it to its owner now, or discard it and mint another. Losing it is not
 * a support ticket, it is a `revoke()` and a second `create()`.
 *
 * Do not log this object. `$key` is a secret and PHP's var_dump, most error
 * handlers, and every "log the response" middleware will print it.
 */
final class ApiKeyWithSecret extends ApiKeySummary
{
    /** @param list<string> $scopes */
    private function __construct(
        string $id,
        string $communityId,
        string $name,
        array $scopes,
        ?string $lastUsedAt,
        ?string $revokedAt,
        string $createdAt,
        /** The plaintext key: `gurb_<id>_<secret>`. Shown once, ever. */
        public readonly string $key,
    ) {
        parent::__construct($id, $communityId, $name, $scopes, $lastUsedAt, $revokedAt, $createdAt);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            communityId: Decode::str($data, 'communityId'),
            name: Decode::str($data, 'name'),
            scopes: Decode::strList($data, 'scopes'),
            lastUsedAt: Decode::nullableStr($data, 'lastUsedAt'),
            revokedAt: Decode::nullableStr($data, 'revokedAt'),
            createdAt: Decode::str($data, 'createdAt'),
            key: Decode::str($data, 'key'),
        );
    }
}
