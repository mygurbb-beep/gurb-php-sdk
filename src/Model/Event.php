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
        /**
         * «تسجيل الزوار» (1.10.0). Null when the response does not carry it (a backend without
         * the feature) — read null as false. See EventsResource::create() for each setting.
         */
        public readonly ?bool $guestRegistration = null,
        public readonly ?bool $approvalGuests = null,
        public readonly ?bool $approvalMembers = null,
        /**
         * The registration questions (join-form document, questions only), or null.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $registrationQuestions = null,
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
            guestRegistration: Decode::nullableBool($data, 'guestRegistration'),
            approvalGuests: Decode::nullableBool($data, 'approvalGuests'),
            approvalMembers: Decode::nullableBool($data, 'approvalMembers'),
            registrationQuestions: Decode::nullableObj($data, 'registrationQuestions'),
        );
    }
}
