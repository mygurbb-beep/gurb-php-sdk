<?php

declare(strict_types=1);

namespace Gurb\Input;

/**
 * Social links a partner may seed on a member's profile (≤ 500 each).
 *
 * Same rule as {@see MemberProfileInput}: null leaves the link untouched, ''
 * clears it. `$x` is also mirrored into the legacy `twitter` column server-side.
 */
final class MemberSocialLinks
{
    public function __construct(
        public readonly ?string $linkedin = null,
        public readonly ?string $x = null,
        public readonly ?string $instagram = null,
        public readonly ?string $facebook = null,
        public readonly ?string $youtube = null,
        public readonly ?string $tiktok = null,
        public readonly ?string $snapchat = null,
        public readonly ?string $telegram = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        // Omitted, not null: an absent key is "untouched" on the server.
        return \array_filter([
            'linkedin' => $this->linkedin,
            'x' => $this->x,
            'instagram' => $this->instagram,
            'facebook' => $this->facebook,
            'youtube' => $this->youtube,
            'tiktok' => $this->tiktok,
            'snapchat' => $this->snapchat,
            'telegram' => $this->telegram,
        ], static fn (?string $value): bool => $value !== null);
    }
}
