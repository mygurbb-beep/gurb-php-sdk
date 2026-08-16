<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * The community record as a BRANDING WRITE returns it.
 *
 * A DIFFERENT CLASS FROM `Community`, and not for tidiness. `Community` is what
 * `GET /sdk/community` publishes and it carries `memberCount` and `createdAt`;
 * this response carries neither, but does carry the Arabic names, the colours
 * and the location that `Community` has no room for. Decoding this payload into
 * `Community` would hand back a member count of zero and a creation date of ""
 * — two invented facts — on every rename.
 *
 * There is no `isPublic`, in either direction. `$type` is the single source of
 * truth for visibility; the boolean it replaced was a duplicate that desynced.
 */
final class CommunityProfile
{
    private function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $nameArabic,
        public readonly ?string $description,
        public readonly ?string $descriptionArabic,
        /** `PUBLIC` or `PRIVATE`. Use `isPublic()` rather than storing a boolean. */
        public readonly string $type,
        public readonly ?string $primaryColor,
        public readonly ?string $secondaryColor,
        /**
         * NULL ON ANY WRITE THAT DID NOT TOUCH IT, even when the community has
         * one stored. The platform echoes back the value the CALLER sent for
         * this field rather than reading the column, so treat a null here as "no
         * answer" and not as "no category".
         */
        public readonly ?string $category,
        public readonly ?string $country,
        public readonly ?string $city,
        public readonly ?string $logoUrl,
        public readonly ?string $bannerUrl,
        public readonly ?string $updatedAt,
    ) {
    }

    public function isPublic(): bool
    {
        return $this->type === 'PUBLIC';
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            slug: Decode::str($data, 'slug'),
            name: Decode::str($data, 'name'),
            nameArabic: Decode::nullableStr($data, 'nameArabic'),
            description: Decode::nullableStr($data, 'description'),
            descriptionArabic: Decode::nullableStr($data, 'descriptionArabic'),
            // Defaults to PRIVATE when unreadable, the same closed-direction
            // fallback `Community` makes: a public community wrongly read as
            // private is a rendering omission, the reverse is a leak.
            type: Decode::str($data, 'type', 'PRIVATE'),
            primaryColor: Decode::nullableStr($data, 'primaryColor'),
            secondaryColor: Decode::nullableStr($data, 'secondaryColor'),
            category: Decode::nullableStr($data, 'category'),
            country: Decode::nullableStr($data, 'country'),
            city: Decode::nullableStr($data, 'city'),
            logoUrl: Decode::nullableStr($data, 'logoUrl'),
            bannerUrl: Decode::nullableStr($data, 'bannerUrl'),
            updatedAt: Decode::nullableStr($data, 'updatedAt'),
        );
    }
}
