<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\CommunityRequestStatus;
use Gurb\Internal\Decode;

/**
 * An application to create a community, and its verdict.
 *
 * This object is the whole point of the approval flow: a community key cannot
 * create a community, so what it gets back from asking is not a `Community` but
 * a piece of paper saying someone will look at it.
 */
final class CommunityRequest
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        /**
         * 'PENDING' | 'APPROVED' | 'REJECTED'.
         *
         * A STRING, not the CommunityRequestStatus enum, even though the enum
         * exists two files away and is used for the list filter. Decoding is
         * tolerant on purpose (see Internal\Decode): `CommunityRequestStatus::
         * from()` would throw on a status the platform adds later, turning an
         * additive backend deploy into an outage for every host site. Use
         * `status()` if you want the enum and can handle null.
         */
        public readonly string $status,
        /** The `User.id` who asked. */
        public readonly string $requestedByUserId,
        /** The super-admin who decided. Null while PENDING. */
        public readonly ?string $decidedByUserId,
        public readonly ?string $decidedAt,
        /** Why it was refused. Always null while PENDING or once APPROVED. */
        public readonly ?string $rejectionReason,
        /**
         * The community this became. NULL UNTIL APPROVED — check `isApproved()`
         * before using it, because a PENDING request has no community and
         * treating this as a string is how you get an empty-string community id
         * in a URL.
         */
        public readonly ?string $communityId,
        public readonly string $createdAt,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === CommunityRequestStatus::Pending->value;
    }

    public function isApproved(): bool
    {
        return $this->status === CommunityRequestStatus::Approved->value;
    }

    public function isRejected(): bool
    {
        return $this->status === CommunityRequestStatus::Rejected->value;
    }

    /** The enum form, or null for a status this SDK version predates. */
    public function status(): ?CommunityRequestStatus
    {
        return CommunityRequestStatus::tryFrom($this->status);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            name: Decode::str($data, 'name'),
            slug: Decode::str($data, 'slug'),
            description: Decode::nullableStr($data, 'description'),
            // Defaulting to PENDING rather than '' : an unreadable status is far
            // more likely to be a request still waiting than one already acted
            // on, and "not decided yet" is the safe assumption to display.
            status: Decode::str($data, 'status', CommunityRequestStatus::Pending->value),
            requestedByUserId: Decode::str($data, 'requestedByUserId'),
            decidedByUserId: Decode::nullableStr($data, 'decidedByUserId'),
            decidedAt: Decode::nullableStr($data, 'decidedAt'),
            rejectionReason: Decode::nullableStr($data, 'rejectionReason'),
            communityId: Decode::nullableStr($data, 'communityId'),
            createdAt: Decode::str($data, 'createdAt'),
        );
    }
}
