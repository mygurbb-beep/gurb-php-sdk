<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

final class EmbedSession
{
    private function __construct(
        /**
         * Single-use, ~120s, `aud: "embed"`. Accepted by exactly one endpoint
         * (the exchange), so it is an entry ticket rather than an API
         * credential. Put it in a URL fragment, never a query string.
         *
         * Do not cache it. Mint one per page load: it is the short life plus the
         * single use that make it safe to hand to a browser at all.
         */
        public readonly string $token,
        public readonly string $expiresAt,
        /** The Gurb user this external identity resolved to (created on first sight). */
        public readonly string $userId,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            token: Decode::str($data, 'token'),
            expiresAt: Decode::str($data, 'expiresAt'),
            userId: Decode::str($data, 'userId'),
        );
    }
}
