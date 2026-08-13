<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\GurbErrorCode;
use Gurb\Internal\Decode;

/**
 * One row that did not land, and enough information to fix it.
 *
 * This is a VALUE, not an exception. That is the whole contract of a bulk
 * endpoint: one bad email address in a list of 500 must not discard the other
 * 499, so a row failure is data you inspect rather than a throw that unwinds
 * past everything that worked.
 */
final class BulkFailure
{
    private function __construct(
        /**
         * The position in the array YOU sent, zero-based.
         *
         * The reason this field exists: the server does not know your variable
         * names, and `externalUserId` alone is not enough to fix a row you built
         * from a spreadsheet. With the index you can write
         * `$rows[$failure->index]` and see exactly what you sent.
         */
        public readonly int $index,
        public readonly string $externalUserId,
        /** A stable GurbErrorCode. Branch on this, never on the message. */
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            index: Decode::int($data, 'index'),
            externalUserId: Decode::str($data, 'externalUserId'),
            code: Decode::str($data, 'code', GurbErrorCode::UNKNOWN),
            message: Decode::str($data, 'message'),
        );
    }
}
