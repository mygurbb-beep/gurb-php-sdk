<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * What `events->listRegistrations()` returns. Not paginated: newest first, up to 1000.
 *
 * Same shape as `EventRegistrationList` in `@gurb/api-types`.
 */
final class EventRegistrationList
{
    private function __construct(
        /** @var list<EventRegistration> */
        public readonly array $items,
        /**
         * Totals per status, whatever the filter was.
         *
         * @var array{PENDING: int, GOING: int, DECLINED: int}
         */
        public readonly array $counts,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $counts = Decode::obj($data, 'counts');

        return new self(
            items: \array_map(EventRegistration::fromArray(...), Decode::rows($data, 'items')),
            counts: [
                'PENDING' => Decode::int($counts, 'PENDING'),
                'GOING' => Decode::int($counts, 'GOING'),
                'DECLINED' => Decode::int($counts, 'DECLINED'),
            ],
        );
    }
}
