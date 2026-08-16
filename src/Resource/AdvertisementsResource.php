<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Advertisement;
use Gurb\Model\Paginated;

/**
 * A community's advertisement placements.
 *
 * ⚠️ READ THIS BEFORE DEBUGGING A 403 HERE. EVERY method in this class,
 * including `active()` — the member-facing widget feed that carries no
 * permission requirement at all — is behind the `ads management` plan feature. A
 * community without it gets 403 FEATURE_NOT_AVAILABLE on its own ad widget.
 *
 * That exact combination has already been misdiagnosed in production: members
 * could not see ads, everyone went looking for a missing permission, and the
 * answer was an entitlement gap. An entitlement is not a permission. No grant,
 * no role and no key fixes it — only the community's plan does. The refusal
 * names the feature key for that reason.
 *
 * PERMISSIONS, WHICH DIFFER PER METHOD BECAUSE THEY DIFFER IN GURB:
 *
 *   - `active()` — none. It is the member-facing feed and Gurb guards it with
 *     nothing but the feature gate. Requiring something here "because it is a
 *     public API" would refuse the exact call the widget makes.
 *   - `list()`, `update()`, `delete()` — `ads:manage` OR `homepage:customize`.
 *     `list()` returns inactive and expired ads, which makes it an admin view,
 *     which is why it carries the writes' permission rather than none.
 *
 * A plain member holds neither of those: `MEMBER` is an empty permission set in
 * Gurb. So a key whose owner was granted nothing can read the ads members see
 * and can do nothing else here — correct, not a misconfiguration.
 *
 * THERE IS NO `create()`, AND THERE CANNOT BE ONE YET. An ad is its image, the
 * image is a file upload, and this surface has no uploads; Gurb's own create
 * refuses a request without a file. Accepting an external `imageUrl` instead
 * would write a value the web app's asset resolver was never taught to render.
 * Create ads in the dashboard and manage their schedule, targeting and
 * activation here.
 */
final class AdvertisementsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * The ads a member would actually see right now: active, in date, and — if
     * you ask — in one position.
     *
     * NOT PAGINATED. It is a widget feed, and it is short by construction.
     *
     * @param string|null $position Filter to one placement: `RIGHT_SIDEBAR`,
     *                              `LEFT_SIDEBAR` or `ABOVE_TWEET_CREATOR`.
     *                              Omitted entirely when null, so the server
     *                              decides.
     *
     * @return list<Advertisement>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when the plan lacks ads
     *                          management — see the class note before reading
     *                          that as a permission problem.
     */
    public function active(?string $position = null): array
    {
        $data = $this->requester->request(
            'GET',
            'sdk/customization/advertisements/active',
            // Null is dropped from the query string rather than sent as the
            // literal "null", which the server would try to match a position
            // against.
            ['position' => $position],
        );

        $items = $data['items'] ?? [];
        if (!\is_array($items)) {
            return [];
        }

        $ads = [];
        foreach ($items as $row) {
            if (\is_array($row)) {
                $ads[] = Advertisement::fromArray($row);
            }
        }

        return $ads;
    }

    /**
     * Every ad, including the inactive and the expired. An ADMIN view — hence
     * `ads:manage` or `homepage:customize`, the same permission as the writes.
     *
     * @return Paginated<Advertisement>
     *
     * @throws GurbApiException
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request(
                'GET',
                'sdk/customization/advertisements',
                ['page' => $page, 'limit' => $limit],
            ),
            Advertisement::fromArray(...),
        );
    }

    /**
     * Change an ad's schedule, link, placement or activation.
     *
     * `ads:manage` OR `homepage:customize`, and no authorship shortcut — an ad
     * belongs to the community, not to whoever uploaded it.
     *
     * `imageUrl`, `image` and `advertisement` are REFUSED rather than ignored:
     * the image is a file upload and the server would discard the field, which
     * is the worst possible answer to give a caller.
     *
     * `linkUrl: null` clears the link. `isGuestVisible: true` can fail for a
     * PRIVATE community, with its own code — that is a real product rule, not a
     * permission problem.
     *
     * An id naming another community's ad is a 404, identical to an id naming
     * nothing, so this endpoint cannot be used to discover what exists
     * elsewhere.
     *
     * @param array<string, mixed> $fields Only what you want changed: `title`,
     *                                     `linkUrl`, `position`
     *                                     (`RIGHT_SIDEBAR` | `LEFT_SIDEBAR` |
     *                                     `ABOVE_TWEET_CREATOR`), `isActive`,
     *                                     `startDate`, `endDate` (ISO 8601, or
     *                                     null to clear), `isGuestVisible`.
     *                                     An empty array is a 400, not a no-op.
     *
     * @throws GurbApiException
     */
    public function update(string $adId, array $fields): Advertisement
    {
        return Advertisement::fromArray($this->requester->request(
            'PATCH',
            // rawurlencode, so a slash inside a caller-supplied id cannot walk
            // the request onto a path this SDK never meant to call.
            'sdk/customization/advertisements/' . \rawurlencode($adId),
            body: $fields,
        ));
    }

    /**
     * Delete an ad. `ads:manage` OR `homepage:customize`.
     *
     * A HARD delete — the row goes, and its impression and click counts with it.
     * There is no `deletedAt` and nothing to restore. If you want the ad off the
     * page but the numbers kept, `update($id, ['isActive' => false])` is the
     * operation you actually want.
     *
     * @throws GurbApiException
     */
    public function delete(string $adId): void
    {
        $this->requester->request(
            'DELETE',
            'sdk/customization/advertisements/' . \rawurlencode($adId),
        );
    }
}
