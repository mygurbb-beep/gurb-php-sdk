<?php

declare(strict_types=1);

namespace Gurb;

/**
 * What can be liked.
 *
 * THREE CASES, NOT FOUR — and the missing one is the point. `CommentParent` has
 * `Events`; this enum does not, because the platform has no event like: its
 * internal service answers `501 Event likes not yet implemented` and its router
 * publishes no like route at all. Rather than publish a URL that cannot work,
 * the SDK makes the call unwritable. If event likes ship, adding a case here is
 * additive and breaks nobody.
 *
 * Comments are likeable too, but through `CommentsResource` — a comment like is
 * addressed by parent AND comment id, so it cannot share this enum's shape.
 */
enum LikeParent: string
{
    /** Home-feed and group posts. The ONLY parent that also runs the community's member-restriction check — a member muted from liking gets 403 RESTRICTION_BLOCKED here and nowhere else. */
    case Tweets = 'tweets';

    /** Gated on the `blogs` plan feature. */
    case Blogs = 'blogs';

    /** Gated on the `albums` plan feature. */
    case Albums = 'albums';

    /** The same value, as a comment parent, for the endpoints that take both. */
    public function asCommentParent(): CommentParent
    {
        return CommentParent::from($this->value);
    }
}
