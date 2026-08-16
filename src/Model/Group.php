<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

final class Group
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $coverUrl,
        public readonly int $memberCount,
        /**
         * Whether the group is private inside the community.
         *
         * PUBLISHED AS A FIELD RATHER THAN FILTERED ON, and that is a deliberate
         * choice about who is asking. An SDK caller holds a community key: it
         * acts *for* the community, not as a visitor to it, so hiding the
         * community's own private groups from its own integration would be
         * theatre — the operator can already see them in the dashboard. What it
         * would break is real: a nightly sync that silently omits half the
         * groups, with nothing in the response to say so.
         *
         * It is your responsibility not to render this list to end users
         * unfiltered. `$group->isPrivate` is how you decide.
         */
        public readonly bool $isPrivate,
        public readonly string $createdAt,
        /**
         * WRITE RESPONSES ONLY — null on every row from `list()`.
         *
         * `GET /sdk/groups` publishes seven fields and this is not one of them;
         * `POST`/`PATCH /sdk/groups` publish four more, of which this is one. A
         * null here is a fact about the endpoint you called, NOT a group without
         * an icon — the icon is required at creation, so no group has none.
         */
        public readonly ?string $iconUrl = null,
        /**
         * The membership CAP, write responses only. `-1` means unlimited.
         *
         * NOT the roster size. `$memberCount` above is the roster on both the
         * read and the write; internally the same word means the cap, and
         * publishing that under one name would give one field two meanings on
         * one surface. Null means "this endpoint did not publish it".
         */
        public readonly ?int $memberLimit = null,
        /** Whether the group has a chat. Write responses only; null on a list row. */
        public readonly ?bool $canChat = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            name: Decode::str($data, 'name'),
            description: Decode::nullableStr($data, 'description'),
            coverUrl: Decode::nullableStr($data, 'coverUrl'),
            memberCount: Decode::int($data, 'memberCount'),
            // Defaults to TRUE when the field is unreadable. Every other decoder
            // in this SDK falls back in the safe direction, and for visibility
            // "safe" means closed: a group wrongly treated as private is a
            // rendering omission, where one wrongly treated as public is a leak.
            isPrivate: Decode::bool($data, 'isPrivate', true),
            createdAt: Decode::str($data, 'createdAt'),
            iconUrl: Decode::nullableStr($data, 'iconUrl'),
            memberLimit: \is_numeric($data['memberLimit'] ?? null) ? (int) $data['memberLimit'] : null,
            canChat: Decode::nullableBool($data, 'canChat'),
        );
    }
}
