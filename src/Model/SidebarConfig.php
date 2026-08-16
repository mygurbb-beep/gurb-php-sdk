<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * The community's navigation menu.
 *
 * `$items` IS PUBLISHED RAW — a list of arrays, not a list of typed menu-item
 * objects — and that is deliberate. The server renames legacy menu ids on the
 * way in, validates the shape and sanitises every label; a typed mirror here
 * would either duplicate those three steps or reject ids the platform
 * deliberately accepts. The vocabulary of valid icons comes from
 * `SidebarResource::icons()`, which publishes it as the bare list it is.
 */
final class SidebarConfig
{
    private function __construct(
        /** Absent reads as TRUE, matching the server. */
        public readonly bool $enabled,
        /** @var list<array<string, mixed>> The menu, in order. */
        public readonly array $items,
        public readonly ?string $lastModified,
        /**
         * A **User.id** — the platform account, not a `CommunityMember.id`.
         *
         * The field is named for what it holds because the two ids are the most
         * confused pair in this platform, and a bare `modifiedBy` would invite a
         * client to compare it against a member id and find no match.
         */
        public readonly ?string $modifiedByUserId,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: Decode::bool($data, 'enabled', true),
            items: Decode::rows($data, 'items'),
            lastModified: Decode::nullableStr($data, 'lastModified'),
            modifiedByUserId: Decode::nullableStr($data, 'modifiedByUserId'),
        );
    }
}
