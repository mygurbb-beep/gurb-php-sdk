<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * An award the community DEFINES — the medal, not the granting of it.
 *
 * `sdk/awards` is a catalogue. Each row is a medal that exists and may be given
 * to someone; it is not a record of anyone receiving one. That distinction is
 * the whole reason `awardedAt` reads the way it does below.
 */
final class Award
{
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $imageUrl,
        /**
         * ALWAYS NULL on this endpoint, and not because of a missing column.
         *
         * WHEN a medal was awarded is a property of the GRANT — a row in
         * `award_recipients` joining a medal to a member — not of the catalogue
         * entry describing the medal. Asking a catalogue row when it was awarded
         * is asking a product when it was bought. There is no single answer, and
         * the honest one is null.
         *
         * Real timestamps belong to a future `awards/{id}/recipients` resource.
         * The field stays in the shape so that resource can arrive without this
         * one's contract changing — and so nobody reads a null here as a bug and
         * "fixes" it by substituting `createdAt`, which is when the medal was
         * *defined* and would be wrong in a way that looks right.
         */
        public readonly ?string $awardedAt,
        /** When the medal was defined. This one is real. */
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            title: Decode::str($data, 'title'),
            description: Decode::nullableStr($data, 'description'),
            imageUrl: Decode::nullableStr($data, 'imageUrl'),
            awardedAt: Decode::nullableStr($data, 'awardedAt'),
            createdAt: Decode::str($data, 'createdAt'),
        );
    }
}
