<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

final class Blog
{
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $excerpt,
        public readonly ?string $coverUrl,
        public readonly Author $author,
        /** Null while the post is still a draft. */
        public readonly ?string $publishedAt,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            title: Decode::str($data, 'title'),
            excerpt: Decode::nullableStr($data, 'excerpt'),
            coverUrl: Decode::nullableStr($data, 'coverUrl'),
            author: Author::fromArray(Decode::obj($data, 'author')),
            publishedAt: Decode::nullableStr($data, 'publishedAt'),
            createdAt: Decode::str($data, 'createdAt'),
        );
    }
}
