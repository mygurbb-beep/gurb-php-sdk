<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\SidebarConfig;

/**
 * The community's navigation menu.
 *
 * EVERY METHOD HERE, READS INCLUDED, needs the `homepage widgets` feature on the
 * community's plan. Without it each one answers 403 FEATURE_NOT_AVAILABLE — an
 * entitlement, not a permission, and no grant will fix it. The gate covers the
 * reads on purpose upstream: per-route gating once left them open and that gap
 * was closed deliberately, so mirroring only the write gate would re-open it
 * from the API side.
 *
 * READING needs no permission beyond membership, which the key already proves on
 * every request. WRITING needs `homepage:customize` OR `settings:manage`. A
 * plain member holds neither — `MEMBER` is an empty permission set in Gurb — so
 * a key whose owner was granted nothing reads the menu and cannot change it.
 * That is correct, not a misconfiguration, and it is the same shape as every
 * other section in this SDK even though the permission NAMES differ; this
 * library mirrors Gurb's own rules per section rather than unifying them.
 *
 * THE HOME-PAGE WIDGETS ARE NOT HERE. `settings.homeWidgets` is a key in the
 * settings blob and is written through `CommunityResource::updateSettings()`,
 * where its merge semantics live. Two endpoints writing one JSON key would be
 * two merge paths to keep in step.
 */
final class SidebarResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * The menu as this community has it configured.
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when the plan lacks the
     *                          homepage-widgets feature.
     */
    public function get(): SidebarConfig
    {
        return SidebarConfig::fromArray($this->requester->request('GET', 'sdk/customization/sidebar'));
    }

    /**
     * Replace the menu.
     *
     * ⚠️ PUT, NOT PATCH, AND THAT IS NOT AN OVERSIGHT. This is the one write on
     * this whole surface that is genuinely a replace, and the SDK mirrors the
     * platform's verb rather than normalising it: sending `$items` REPLACES THE
     * WHOLE ARRAY. There is no per-item merge, so a menu built by appending one
     * entry to a stale read loses everything the read did not contain. Omitting
     * `$items` keeps the stored menu untouched.
     *
     * What it does NOT replace is the rest of the community's settings — a
     * sidebar write swaps only the sidebar key and leaves `pageVisibility` and
     * everything else alone.
     *
     * `$items` is passed through UNVALIDATED on purpose. The server renames
     * legacy menu ids, validates the shape and sanitises every label; checking
     * here would duplicate all three and would reject ids the rename step
     * deliberately accepts. Icon names come from `icons()`.
     *
     * Requires `homepage:customize` OR `settings:manage`.
     *
     * @param list<array<string, mixed>>|null $items   The whole menu, in order.
     * @param bool|null                       $enabled Whether the custom menu is
     *                                                 in effect at all.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) with NO request made
     *                          when neither argument is given.
     */
    public function update(?array $items = null, ?bool $enabled = null): SidebarConfig
    {
        if ($items === null && $enabled === null) {
            throw GurbApiException::localValidation(
                'update needs items and/or enabled.',
            );
        }

        // Omitted rather than sent as null: an absent `items` means "keep the
        // stored menu", and a null would be a value the server has to interpret.
        $body = [];
        if ($items !== null) {
            // array_values keeps this a JSON array. A PHP array with gaps in its
            // keys — the usual result of an array_filter() upstream — encodes as
            // a JSON object, and the server would reject `{"0": {...}}` where it
            // expected a list.
            $body['items'] = \array_values($items);
        }
        if ($enabled !== null) {
            $body['enabled'] = $enabled;
        }

        return SidebarConfig::fromArray(
            $this->requester->request('PUT', 'sdk/customization/sidebar', body: $body),
        );
    }

    /**
     * Reset the menu to the platform default.
     *
     * NOT A FULL WIPE, and the asymmetry is the platform's rather than this
     * SDK's: uploaded section BANNERS survive a reset while custom ICONS do not.
     * A banner is a photograph an admin would have to go and find again; an icon
     * is a pick from a list.
     *
     * Requires `homepage:customize` OR `settings:manage` — the same permission
     * as `update()`, since resetting is a write.
     *
     * @throws GurbApiException
     */
    public function reset(): SidebarConfig
    {
        return SidebarConfig::fromArray(
            $this->requester->request('DELETE', 'sdk/customization/sidebar'),
        );
    }

    /**
     * The default menu, unsaved — useful for diffing before calling `reset()`.
     *
     * No permission; the feature gate still applies.
     *
     * @throws GurbApiException
     */
    public function default(): SidebarConfig
    {
        return SidebarConfig::fromArray(
            $this->requester->request('GET', 'sdk/customization/sidebar/default'),
        );
    }

    /**
     * The icon names a menu item may use.
     *
     * Published as the bare list it is rather than mapped into objects: it IS
     * the vocabulary, and reshaping it would create a second name for every icon
     * that `update()` would then have to translate back.
     *
     * No permission; the feature gate still applies.
     *
     * @return list<string>
     *
     * @throws GurbApiException
     */
    public function icons(): array
    {
        $data = $this->requester->request('GET', 'sdk/customization/sidebar/icons');
        $icons = $data['icons'] ?? [];

        if (!\is_array($icons)) {
            return [];
        }

        // Non-strings dropped rather than coerced, the same tolerant posture the
        // rest of this SDK takes toward a payload that grew a field.
        return \array_values(\array_filter($icons, \is_string(...)));
    }
}
