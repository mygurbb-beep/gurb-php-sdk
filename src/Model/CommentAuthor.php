<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * Who wrote a comment.
 *
 * A SEPARATE CLASS FROM `Author`, and deliberately so: this one publishes TWO
 * ids, because the platform genuinely has two and confusing them is a bug class
 * of its own. `userId` is the platform account; `memberId` is that account's
 * membership row inside this one community. The comment row itself stores the
 * MEMBER id, so a client comparing a stored `userId` against it would find no
 * match and conclude nobody wrote anything — that exact confusion once made
 * members' own likes invisible in production.
 *
 * Use `userId` to join against your own user table if you provisioned members
 * through this SDK. Use `memberId` only when talking about membership.
 */
final class CommentAuthor
{
    private function __construct(
        /** The platform account id, or null if the server could not resolve one. */
        public readonly ?string $userId,
        /** The membership row id inside THIS community. What the comment row stores. */
        public readonly ?string $memberId,
        public readonly string $displayName,
        public readonly ?string $avatarUrl,
        /**
         * They left, or were removed.
         *
         * Their words remain — removal from a community is not authorship
         * erasure — so render "عضو سابق" (former member) rather than implying
         * they are still here. Only a full account deletion anonymises content.
         */
        public readonly bool $isFormerMember,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: Decode::nullableStr($data, 'userId'),
            memberId: Decode::nullableStr($data, 'memberId'),
            displayName: Decode::str($data, 'displayName'),
            avatarUrl: Decode::nullableStr($data, 'avatarUrl'),
            isFormerMember: Decode::bool($data, 'isFormerMember'),
        );
    }
}
