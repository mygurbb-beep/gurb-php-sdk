<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

final class Event
{
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $location,
        public readonly ?string $coverUrl,
        /** ISO-8601 UTC. Kept as a string so the SDK never guesses a timezone. */
        public readonly string $startsAt,
        public readonly ?string $endsAt,
        public readonly int $attendeeCount,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            title: Decode::str($data, 'title'),
            description: Decode::nullableStr($data, 'description'),
            location: Decode::nullableStr($data, 'location'),
            coverUrl: Decode::nullableStr($data, 'coverUrl'),
            startsAt: Decode::str($data, 'startsAt'),
            endsAt: Decode::nullableStr($data, 'endsAt'),
            attendeeCount: Decode::int($data, 'attendeeCount'),
        );
    }
}
