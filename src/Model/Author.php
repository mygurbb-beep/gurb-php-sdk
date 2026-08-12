<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

final class Author
{
    private function __construct(
        /**
         * The platform-level user id.
         *
         * Worth knowing if you ever read the platform's own database: several
         * backend tables store a `CommunityMember.id` in a column named
         * `authorId`. This field is always the `User.id` — the API normalises it
         * before it crosses the wire, so SDK consumers never meet that trap.
         */
        public readonly string $userId,
        public readonly string $displayName,
        public readonly ?string $avatarUrl,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: Decode::str($data, 'userId'),
            displayName: Decode::str($data, 'displayName'),
            avatarUrl: Decode::nullableStr($data, 'avatarUrl'),
        );
    }
}
