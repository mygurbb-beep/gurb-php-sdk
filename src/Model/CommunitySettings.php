<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * How a community is configured: which menu sections show, what the home page
 * carries, whether members may message each other.
 *
 * MOST OF THIS IS PUBLISHED AS RAW ARRAYS, ON PURPOSE. `homeWidgets`,
 * `backButton`, `widgetConfig`, `guestAccess`, `advertisements` and
 * `sidebarCustomization` are open-ended JSON blobs whose shapes the platform
 * extends as it ships features. Typing them here would mean an additive backend
 * deploy silently dropping fields this SDK had not been taught yet — the failure
 * an integrator cannot see. `pageVisibility` is the exception, because it is
 * genuinely `string => bool` and clients branch on it.
 *
 * `$pageVisibility` ALWAYS CARRIES EVERY MENU ID, whatever is stored: the server
 * floors the stored map with the registry's defaults before publishing it. So an
 * absent key is never a thing you have to handle, and you must not read one as
 * "hidden". Note the defaults are NOT all `true` — `projects2` and `tree` are
 * opt-in and default to false — and `home` is always forced true.
 */
final class CommunitySettings
{
    private function __construct(
        /** @var array<string, bool> Every menu id, floored with the platform defaults. */
        public readonly array $pageVisibility,
        /**
         * Whether members may direct-message each other.
         *
         * Absent means ENABLED — the platform publishes "not false" — so the
         * default here is `true` and not `false`.
         */
        public readonly bool $directMessagesEnabled,
        /** @var array<string, mixed>|null Null means never configured, not "configured empty". */
        public readonly ?array $homeWidgets,
        /** @var array<string, mixed> Defaults to `['enabled' => false]` upstream. */
        public readonly array $advertisements,
        /** @var array<string, mixed>|null */
        public readonly ?array $backButton,
        /** @var array<string, mixed>|null */
        public readonly ?array $widgetConfig,
        /** @var array<string, mixed> What anonymous visitors may see, section by section. */
        public readonly array $guestAccess,
        /**
         * The stored sidebar blob, as a settings key.
         *
         * `SidebarResource::get()` is the endpoint that publishes this properly,
         * with its own shape and its own feature gate. This copy is here because
         * the settings read returns it; prefer the resource.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $sidebarCustomization,
        /**
         * Colours and explore-listing state.
         *
         * NON-NULL on the READ and NULL on the response to a settings PATCH —
         * the write answers with the settings blob alone. A null here is a fact
         * about which call you made, not about the community.
         */
        public readonly ?CommunityBranding $branding = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            pageVisibility: Decode::boolMap($data, 'pageVisibility'),
            // Absent reads as TRUE, matching the server.
            directMessagesEnabled: Decode::bool($data, 'directMessagesEnabled', true),
            homeWidgets: Decode::nullableObj($data, 'homeWidgets'),
            advertisements: Decode::obj($data, 'advertisements'),
            backButton: Decode::nullableObj($data, 'backButton'),
            widgetConfig: Decode::nullableObj($data, 'widgetConfig'),
            guestAccess: Decode::obj($data, 'guestAccess'),
            sidebarCustomization: Decode::nullableObj($data, 'sidebarCustomization'),
            branding: \is_array($data['branding'] ?? null)
                ? CommunityBranding::fromArray($data['branding'])
                : null,
        );
    }
}
