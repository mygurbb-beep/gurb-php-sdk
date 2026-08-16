<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * The answer every like endpoint gives, whichever verb produced it.
 *
 * GET, POST and DELETE all return this same shape, so a UI can render from the
 * response of the action it just took without a follow-up read.
 */
final class LikeState
{
    private function __construct(
        /**
         * Whether the KEY'S OWNER likes this row — not whether anyone does.
         *
         * The identity here is the human the API key was minted for. An
         * integration acting on behalf of many end users cannot express "did
         * THIS visitor like it" through a server key; that is what the embed
         * session flow is for.
         */
        public readonly bool $liked,
        /** Everyone's likes, including the key owner's. */
        public readonly int $likeCount,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            liked: Decode::bool($data, 'liked'),
            likeCount: Decode::int($data, 'likeCount'),
        );
    }
}
