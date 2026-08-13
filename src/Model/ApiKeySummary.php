<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * A key as the dashboard sees it: everything except the secret.
 *
 * NOT `final`, and its constructor is `protected` rather than `private` — the
 * one place in this SDK where that is true. `ApiKeyWithSecret` extends it, which
 * mirrors the TypeScript type (`interface ApiKeyWithSecret extends
 * ApiKeySummary`) and means a caller can pass a freshly-minted key anywhere a
 * summary is expected. Composition would have worked too, at the cost of
 * `$key->summary->name` everywhere.
 */
class ApiKeySummary
{
    /** @param list<string> $scopes */
    protected function __construct(
        /** The key id — the `<12 hex>` in the middle of the key itself. Safe to log. */
        public readonly string $id,
        public readonly string $communityId,
        /** Shown in the dashboard so a human can tell keys apart later. */
        public readonly string $name,
        /**
         * 'read' and/or 'write'.
         *
         * A list of strings, not ApiKeyScope enums, for the usual reason: this
         * is decoded from the server, and a scope added after this SDK was
         * released must render rather than throw. `ApiKeyScope` is for the
         * `create()` call, where you are choosing from what exists today.
         */
        public readonly array $scopes,
        /** Null means never used. Useful for finding keys safe to revoke. */
        public readonly ?string $lastUsedAt,
        public readonly ?string $revokedAt,
        public readonly string $createdAt,
    ) {
    }

    /**
     * Revocation is one-way, so this is a fact and not a state that may flip
     * back. That is deliberate on the platform's side: "is this key still live?"
     * gets exactly one answer, forever.
     */
    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /**
     * `new self`, not `new static`: the subclass needs one more constructor
     * argument than this method knows about, so late static binding here would
     * be an ArgumentCountError waiting for someone to call
     * `ApiKeyWithSecret::fromArray()` after deleting its override. The subclass
     * builds itself; this only ever builds a summary.
     *
     * @param array<string, mixed> $data
     */
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
        );
    }
}
