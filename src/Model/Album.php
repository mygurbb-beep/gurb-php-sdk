<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

final class Album
{
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $coverUrl,
        public readonly int $photoCount,
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
            coverUrl: Decode::nullableStr($data, 'coverUrl'),
            photoCount: Decode::int($data, 'photoCount'),
            createdAt: Decode::str($data, 'createdAt'),
        );
    }
}
