<?php

declare(strict_types=1);

namespace Gurb;

/**
 * What a comment hangs off.
 *
 * An enum rather than a string because the parent is a PATH SEGMENT, and the
 * backend generates its comment routes from a fixed table of four parents. A
 * string argument would let a caller build `sdk/photos/x/comments`, which does
 * not exist — and an unmatched `/api/*` path on that backend answers 400
 * "Tenant context required" rather than 404, so the mistake would look like a
 * credentials problem.
 *
 * WHY THE PARENT IS IN THE PATH AT ALL, given that a comment id is unique: the
 * platform's comments live in one table keyed by the PAIR (resource type,
 * resource id), and that table has no `communityId` column. Naming the parent is
 * how the server checks the comment is inside the community your key is pinned
 * to. Passing the wrong parent for a real comment id is a 404, not a silent
 * cross-community edit.
 *
 * FEATURE GATES DIFFER PER PARENT. Blogs, albums and events sit behind their
 * module's plan flag, so commenting on them answers 403 FEATURE_NOT_AVAILABLE
 * for a community whose plan lacks it — on reads as well as writes. Tweets carry
 * no gate. That asymmetry is the platform's and is mirrored, not smoothed out.
 */
enum CommentParent: string
{
    /** Home-feed and group posts. No feature gate. */
    case Tweets = 'tweets';

    /** Gated on the `blogs` plan feature. */
    case Blogs = 'blogs';

    /** Gated on the `albums` plan feature. */
    case Albums = 'albums';

    /** Gated on the `events` plan feature. */
    case Events = 'events';
}
