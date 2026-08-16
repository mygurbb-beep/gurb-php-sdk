<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * A community's colours and its explore-listing state.
 *
 * A SEPARATE CLASS RATHER THAN THREE MORE FIELDS ON `CommunitySettings`, because
 * these are not settings: they are columns on the community record which the
 * settings read happens to be the only endpoint that returns. Reading them here
 * saves a second round trip for a branding dialog; writing them is
 * `CommunityResource::updateBranding()`, which answers with a `CommunityProfile`
 * instead.
 */
final class CommunityBranding
{
    private function __construct(
        /** Hex, e.g. `#093431`. Null when the community has never set one. */
        public readonly ?string $primaryColor,
        public readonly ?string $secondaryColor,
        /**
         * Whether the community is listed in Gurb's public explore directory.
         *
         * READ-ONLY on this surface — there is no field for it on any write
         * here. It is not the same question as `type`: a PUBLIC community can be
         * unlisted, and being listed is what puts it in front of strangers.
         */
        public readonly bool $isExplorable,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            primaryColor: Decode::nullableStr($data, 'primaryColor'),
            secondaryColor: Decode::nullableStr($data, 'secondaryColor'),
            // Defaults to FALSE when unreadable, which is the closed direction:
            // a community wrongly shown as unlisted is a missing row in someone's
            // dashboard, where the opposite claims a private community is
            // advertised to the internet.
            isExplorable: Decode::bool($data, 'isExplorable'),
        );
    }
}
