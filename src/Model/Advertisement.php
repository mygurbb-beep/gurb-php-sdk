<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * One advertisement placement inside a community.
 *
 * There is no `communityId` on this shape. The key IS the community, and
 * echoing it back would invite a client to start sending it.
 */
final class Advertisement
{
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        /**
         * The image, which this SDK can read and cannot write.
         *
         * An ad's image is a file upload, so it is set in the dashboard. There
         * is deliberately no `imageUrl` field on the update contract — the
         * server would discard it, and an arbitrary external URL is not a
         * substitute for one Gurb's own storage issued.
         */
        public readonly ?string $imageUrl,
        public readonly ?string $linkUrl,
        /** `RIGHT_SIDEBAR`, `LEFT_SIDEBAR` or `ABOVE_TWEET_CREATOR`. */
        public readonly string $position,
        public readonly bool $isActive,
        /** Whether anonymous visitors see it. Refused for a PRIVATE community. */
        public readonly bool $isGuestVisible,
        public readonly int $clickCount,
        public readonly int $impressionCount,
        /** Schedule start. `startDate` upstream; `startsAt` on this surface. */
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            title: Decode::str($data, 'title'),
            imageUrl: Decode::nullableStr($data, 'imageUrl'),
            linkUrl: Decode::nullableStr($data, 'linkUrl'),
            position: Decode::str($data, 'position'),
            // Absent reads as TRUE, matching the server's `isActive !== false`.
            isActive: Decode::bool($data, 'isActive', true),
            // Absent reads as FALSE: guest visibility is an opt-in, and the safe
            // direction for "who can see this" is closed.
            isGuestVisible: Decode::bool($data, 'isGuestVisible'),
            clickCount: Decode::int($data, 'clickCount'),
            impressionCount: Decode::int($data, 'impressionCount'),
            startsAt: Decode::nullableStr($data, 'startsAt'),
            endsAt: Decode::nullableStr($data, 'endsAt'),
            createdAt: Decode::nullableStr($data, 'createdAt'),
            updatedAt: Decode::nullableStr($data, 'updatedAt'),
        );
    }
}
