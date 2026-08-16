<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * A comment, and — recursively — its replies.
 *
 * The server nests replies to unlimited depth rather than returning a flat list
 * with parent pointers, so a renderer can walk `$comment->replies` directly. A
 * top-level comment has `parentCommentId === null`; a reply carries the id of
 * what it answers.
 *
 * PAGINATION COUNTS TOP-LEVEL COMMENTS. Replies travel inside their parent and
 * are not paged, so a page of 20 may contain far more than 20 comments in total.
 */
final class Comment
{
    private function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly CommentAuthor $author,
        /** Null for a top-level comment; the id of the answered comment for a reply. */
        public readonly ?string $parentCommentId,
        /** @var list<string> */
        public readonly array $attachmentUrls,
        public readonly int $likeCount,
        /** Whether the KEY'S OWNER likes it — see LikeState::$liked. */
        public readonly bool $likedByMe,
        public readonly bool $isEdited,
        public readonly ?string $editedAt,
        public readonly ?string $createdAt,
        /** @var list<Comment> */
        public readonly array $replies,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            content: Decode::str($data, 'content'),
            author: CommentAuthor::fromArray(Decode::obj($data, 'author')),
            parentCommentId: Decode::nullableStr($data, 'parentCommentId'),
            attachmentUrls: Decode::strList($data, 'attachmentUrls'),
            likeCount: Decode::int($data, 'likeCount'),
            likedByMe: Decode::bool($data, 'likedByMe'),
            isEdited: Decode::bool($data, 'isEdited'),
            editedAt: Decode::nullableStr($data, 'editedAt'),
            createdAt: Decode::nullableStr($data, 'createdAt'),
            replies: \array_map(self::fromArray(...), Decode::rows($data, 'replies')),
        );
    }
}
