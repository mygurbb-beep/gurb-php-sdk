<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

final class Tweet
{
    private function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly Author $author,
        /** @var list<string> */
        public readonly array $imageUrls,
        public readonly int $likeCount,
        public readonly int $commentCount,
        public readonly string $createdAt,
        /** Non-null means the post was edited (within 30 minutes of posting). */
        public readonly ?string $editedAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            content: Decode::str($data, 'content'),
            author: Author::fromArray(Decode::obj($data, 'author')),
            imageUrls: Decode::strList($data, 'imageUrls'),
            likeCount: Decode::int($data, 'likeCount'),
            commentCount: Decode::int($data, 'commentCount'),
            createdAt: Decode::str($data, 'createdAt'),
            editedAt: Decode::nullableStr($data, 'editedAt'),
        );
    }
}
