<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * One person who registered for an event — «طلبات التسجيل». A member, or a guest (`isGuest`) who
 * registered through the event's shared link. Organisers see a guest's name and e-mail here; every
 * other roster shows a guest as «زائر».
 *
 * Same shape as `EventRegistration` in `@gurb/api-types`.
 */
final class EventRegistration
{
    private function __construct(
        /** A `User.id` — what `approveRegistration()` / `declineRegistration()` take. */
        public readonly string $userId,
        /** As THIS community knows the member; a guest's own name. */
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $avatar,
        public readonly bool $isGuest,
        /** 'PENDING' | 'GOING' | 'DECLINED'. */
        public readonly string $status,
        /**
         * Answers to the event's questions keyed by question id, or null when it asked none.
         * A value is a string, a number, a bool, `['optionId' => …]`, `['other' => …]` or
         * `['optionIds' => […], 'other' => …]`.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $answers,
        /** ISO-8601 UTC. */
        public readonly ?string $createdAt,
        /** ISO-8601 UTC — when an organiser approved or declined; null while pending. */
        public readonly ?string $decidedAt,
        public readonly ?string $declineReason,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: Decode::str($data, 'userId'),
            name: Decode::str($data, 'name'),
            email: Decode::nullableStr($data, 'email'),
            avatar: Decode::nullableStr($data, 'avatar'),
            isGuest: Decode::bool($data, 'isGuest'),
            status: Decode::str($data, 'status'),
            answers: Decode::nullableObj($data, 'answers'),
            createdAt: Decode::nullableStr($data, 'createdAt'),
            decidedAt: Decode::nullableStr($data, 'decidedAt'),
            declineReason: Decode::nullableStr($data, 'declineReason'),
        );
    }
}
