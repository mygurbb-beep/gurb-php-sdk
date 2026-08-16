<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * A consultancy LISTING, not a person.
 *
 * The table behind this models an offered service. That one fact explains the
 * two odd-looking things in the shape below: `title` has no backing column and
 * is always null today, and `specialties` comes from a relation rather than a
 * field.
 */
final class Consultant
{
    private function __construct(
        public readonly string $id,
        public readonly string $displayName,
        /**
         * ALWAYS NULL TODAY. Do not build a UI that assumes otherwise.
         *
         * The field is in the shape on purpose even though nothing populates it:
         * when a title column arrives, it starts arriving here and no consumer
         * has to change, because a `string|null` that becomes non-null is not a
         * breaking change while adding a field to a response is a contract
         * negotiation. What this SDK will not do is invent a source for it —
         * falling back to `displayName`, or to a specialty, would produce a
         * value that looks authored and is not.
         */
        public readonly ?string $title,
        public readonly ?string $bio,
        public readonly ?string $avatarUrl,
        /**
         * From a relation, so it is a list and may legitimately be empty.
         *
         * @var list<string>
         */
        public readonly array $specialties,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            displayName: Decode::str($data, 'displayName'),
            // nullableStr, not str: a JSON null must survive as PHP null rather
            // than collapsing to '', because "" reads as "they have a title and
            // it is blank" and null reads as "there is no title here". Those are
            // different claims and only one of them is true.
            title: Decode::nullableStr($data, 'title'),
            bio: Decode::nullableStr($data, 'bio'),
            avatarUrl: Decode::nullableStr($data, 'avatarUrl'),
            specialties: Decode::strList($data, 'specialties'),
            createdAt: Decode::str($data, 'createdAt'),
        );
    }
}
