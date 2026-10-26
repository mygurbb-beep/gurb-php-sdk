<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * What `contentInvitations->create()` did — «دعوة للمشاهدة».
 *
 * Same shape as `ContentInvitationsResult` in `@gurb/api-types`. A skip reason is 'SELF',
 * 'ALREADY_INVITED' or 'UNAVAILABLE' — the last deliberately covers «not a member», «hidden from
 * your member directory» and «cannot open the item», so ids cannot be probed for membership.
 */
final class ContentInvitationsResult
{
    private function __construct(
        /** @var list<string> User ids that were invited (and notified). */
        public readonly array $invited,
        /** @var list<array{userId: string, reason: string}> */
        public readonly array $skipped,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $skipped = [];
        foreach (Decode::rows($data, 'skipped') as $row) {
            $skipped[] = ['userId' => Decode::str($row, 'userId'), 'reason' => Decode::str($row, 'reason')];
        }

        return new self(invited: Decode::strList($data, 'invited'), skipped: $skipped);
    }
}
