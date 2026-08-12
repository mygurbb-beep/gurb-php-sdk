<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

final class Member
{
    private function __construct(
        /** Platform-wide identity. This is what an embed session resolves to. */
        public readonly string $userId,
        /**
         * Membership row id, scoped to this community.
         *
         * Two ids because they answer different questions: `userId` is "which
         * human", `memberId` is "their membership here". They are not
         * interchangeable, and mixing them up is the single most common bug in
         * platform code that touches this data.
         */
        public readonly string $memberId,
        public readonly string $displayName,
        public readonly ?string $avatarUrl,
        /** 'ADMIN' | 'MODERATOR' | 'MEMBER'. Read-only through this SDK. */
        public readonly string $role,
        public readonly string $joinedAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: Decode::str($data, 'userId'),
            memberId: Decode::str($data, 'memberId'),
            displayName: Decode::str($data, 'displayName'),
            avatarUrl: Decode::nullableStr($data, 'avatarUrl'),
            role: Decode::str($data, 'role', 'MEMBER'),
            joinedAt: Decode::str($data, 'joinedAt'),
        );
    }
}
