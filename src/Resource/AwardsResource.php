<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Award;
use Gurb\Model\Paginated;

final class AwardsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * The catalogue of medals this community defines.
     *
     * THIS IS NOT A LIST OF AWARDS ANYONE RECEIVED. Each row is a medal that
     * exists; who holds it, and since when, lives on the grant and not here —
     * which is why every `Award::$awardedAt` comes back null. Read the note on
     * that property before displaying a date next to one of these.
     *
     * @return Paginated<Award>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when awards are not on this
     *                          community's plan.
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/awards', ['page' => $page, 'limit' => $limit]),
            Award::fromArray(...),
        );
    }

    /**
     * DEFINE a medal. This does NOT give it to anybody.
     *
     * GRANTING IS NOT ON THIS SURFACE AT ALL, and that is a product decision
     * rather than a gap. Granting writes recipient rows, can name every member
     * of a community in one call, notifies and pushes to each of them, and emits
     * an event carrying every recipient's identity — it turns the catalogue into
     * a public statement about named people. Shipping it quietly alongside CRUD
     * would be publishing a "broadcast member identities" endpoint under the
     * heading "awards write support". Revoking is out for the same reason.
     *
     * The one field here with a granting consequence is `autoAwardNewMembers`,
     * and it is still a property of the DEFINITION: it fires on FUTURE joins
     * only and never retroactively.
     *
     * Requires the `awards` feature on the plan — 403 FEATURE_NOT_AVAILABLE on
     * every call including reads without it, an entitlement no grant fixes.
     *
     * `awards:manage` on create, update AND delete, with NO authorship
     * shortcut anywhere: a medal is a community-wide object, not a member's own
     * content, and there is nothing to own. `awards:moderate` exists upstream
     * but only for hide/unhide, which are not on this surface — accepting it
     * here because it sounds like a moderation permission would let a moderator
     * delete medal definitions the web app does not let them delete. A plain
     * member holds none of this; `MEMBER` is an empty permission set in Gurb.
     *
     * `$icon` IS A PRESET KEY such as `"trophy"`, not a URL — there are no
     * uploads on this surface, and a preset key is the only half of that column
     * a caller can honestly supply. `$color` must be hex (`#093431` or `#093`).
     *
     * @param array<string, mixed> $options `nameArabic` (defaults to `name`
     *                                      server-side), `description`,
     *                                      `descriptionArabic`, `criteria`,
     *                                      `criteriaArabic`, `rarityId`,
     *                                      `categoryId`, `autoAwardNewMembers`.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) with NO request made
     *                          when name, icon or color is blank.
     */
    public function create(string $name, string $icon, string $color, array $options = []): Award
    {
        $trimmedName = \trim($name);
        $trimmedIcon = \trim($icon);
        $trimmedColor = \trim($color);

        if ($trimmedName === '' || $trimmedIcon === '' || $trimmedColor === '') {
            throw GurbApiException::localValidation(
                'An award needs a name, an icon preset key and a hex color.',
            );
        }

        // The trimmed values are what get SENT, not merely what got validated.
        // The hex FORMAT is left to the server on purpose: a second copy of that
        // pattern here is a second thing to keep in sync, and being stricter
        // than Gurb would refuse a colour its own dialog accepts.
        $body = \array_merge($options, [
            'name' => $trimmedName,
            'icon' => $trimmedIcon,
            'color' => $trimmedColor,
        ]);

        return Award::fromArray($this->requester->request('POST', 'sdk/awards', body: $body));
    }

    /**
     * Edit a medal's definition. `awards:manage`, same as create — no authorship
     * shortcut, because nobody owns a community-wide medal.
     *
     * `rarityId: null` and `categoryId: null` genuinely detach the medal from
     * its tier or category. An ABSENT key changes nothing, so a partial update
     * never silently uncategorises an award.
     *
     * @param array<string, mixed> $fields Only what you want changed: `name`,
     *                                     `nameArabic`, `description`,
     *                                     `descriptionArabic`, `criteria`,
     *                                     `criteriaArabic`, `icon`, `color`,
     *                                     `rarityId`, `categoryId`,
     *                                     `autoAwardNewMembers`. An empty array
     *                                     is a 400, not a no-op.
     *
     * @throws GurbApiException
     */
    public function update(string $awardId, array $fields): Award
    {
        return Award::fromArray($this->requester->request(
            'PATCH',
            // rawurlencode, so a slash inside a caller-supplied id cannot walk
            // the request onto a path this SDK never meant to call.
            'sdk/awards/' . \rawurlencode($awardId),
            body: $fields,
        ));
    }

    /**
     * Delete a medal. `awards:manage`.
     *
     * THIS CASCADES TO THE PEOPLE WHO EARNED IT: every recipient row goes with
     * the definition, so every member who holds the medal loses it. That is
     * Gurb's own behaviour, not a harsher SDK one, and it is the reason this
     * method deserves a confirmation step in whatever UI calls it.
     *
     * @throws GurbApiException
     */
    public function delete(string $awardId): void
    {
        $this->requester->request('DELETE', 'sdk/awards/' . \rawurlencode($awardId));
    }
}
