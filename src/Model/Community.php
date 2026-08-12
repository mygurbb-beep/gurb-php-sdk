<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

final class Community
{
    private function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $logoUrl,
        public readonly ?string $bannerUrl,
        /**
         * 'PUBLIC' or 'PRIVATE'. Visibility is this field alone.
         *
         * There is deliberately no `isPublic` boolean. The platform used to
         * carry both and they silently drifted apart, leaving communities that
         * reported one visibility and enforced the other. Derive at the call
         * site instead: `$community->isPublic()`, which reads `type` and cannot
         * go stale.
         */
        public readonly string $type,
        public readonly int $memberCount,
        public readonly string $createdAt,
    ) {
    }

    public function isPublic(): bool
    {
        return $this->type === 'PUBLIC';
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            slug: Decode::str($data, 'slug'),
            name: Decode::str($data, 'name'),
            description: Decode::nullableStr($data, 'description'),
            logoUrl: Decode::nullableStr($data, 'logoUrl'),
            bannerUrl: Decode::nullableStr($data, 'bannerUrl'),
            type: Decode::str($data, 'type', 'PRIVATE'),
            memberCount: Decode::int($data, 'memberCount'),
            createdAt: Decode::str($data, 'createdAt'),
        );
    }
}
