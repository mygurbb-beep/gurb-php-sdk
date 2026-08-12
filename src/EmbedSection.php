<?php

declare(strict_types=1);

namespace Gurb;

/**
 * The sections a host site may embed.
 *
 * One section per iframe by design: a chrome-less pane composes into the host's
 * own layout, where a full-app-in-a-frame drags in scroll height, internal
 * routing, and links trying to escape the frame.
 *
 * A backed enum rather than loose strings so a typo is a compile-time-ish error
 * (`EmbedSection::from('tweeets')` throws) instead of a 404 inside an iframe,
 * which is the least debuggable place a 404 can happen.
 */
enum EmbedSection: string
{
    case Tweets = 'tweets';
    case Events = 'events';
    case Blogs = 'blogs';
    case Albums = 'albums';
}
