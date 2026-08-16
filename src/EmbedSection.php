<?php

declare(strict_types=1);

namespace Gurb;

/**
 * What a host site may embed: the whole community, or one section of it.
 *
 * TWO SHAPES, AND THEY ARE NOT INTERCHANGEABLE
 *
 * `Community` is the community page ITSELF in a frame — navigation, every
 * section the plan enables, and the member's own view of it. The member lands
 * exactly where a normal sign-in would put them and moves around inside the
 * frame. Reach for this when the frame IS the page: a "Community" tab in your
 * app.
 *
 * A SECTION is a chrome-less pane meant to compose into your own layout — your
 * header, your navigation, one Gurb panel among your own content. It has no
 * menu and cannot navigate anywhere.
 *
 * The trade is real and worth knowing before you choose. A whole community in a
 * frame brings its own scroll height (so `autoResize` matters much more), its
 * own internal routing (your back button will not follow it), and links that may
 * try to leave the frame. A section brings none of that.
 *
 * A backed enum rather than loose strings, so a typo is caught at the call site
 * (`EmbedSection::from('tweeets')` throws) instead of becoming a 404 inside an
 * iframe — the least debuggable place a 404 can happen.
 */
enum EmbedSection: string
{
    /**
     * The ENTIRE community, exactly as a member sees it after signing in.
     *
     * Sections their plan or permissions do not allow simply do not appear —
     * the same rules as the app, because it IS the app.
     */
    case Community = 'community';

    case Tweets = 'tweets';
    case Events = 'events';
    case Blogs = 'blogs';
    case Albums = 'albums';

    /** True for the whole-community frame rather than a single pane. */
    public function isWholeCommunity(): bool
    {
        return $this === self::Community;
    }

    /**
     * A starting height that is not immediately wrong, before the frame reports
     * its own.
     *
     * A whole community needs far more room than one pane: at 600px a member
     * sees a navigation bar and little else, and judges the integration broken
     * in the second before auto-resize lands.
     */
    public function defaultHeight(): int
    {
        return $this === self::Community ? 900 : 600;
    }
}
