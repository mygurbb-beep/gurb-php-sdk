<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Consultant;
use Gurb\Model\Paginated;

final class ConsultantsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * The consultancy listings this community publishes.
     *
     * These are OFFERED SERVICES, not staff records — read the note on
     * `Consultant::$title` before you design a card around one.
     *
     * @return Paginated<Consultant>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when consultants are not on
     *                          this community's plan.
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/consultants', ['page' => $page, 'limit' => $limit]),
            Consultant::fromArray(...),
        );
    }

    /**
     * Create a consultancy listing.
     *
     * Requires the `consultants` feature on the community's plan. A community
     * without it answers 403 FEATURE_NOT_AVAILABLE on EVERY call here, reads
     * included — an entitlement, not a permission, and no grant will fix it.
     *
     * THE FLATTEST PERMISSION IN THIS SDK: `consultants:manage` for create,
     * update AND delete, with no authorship shortcut anywhere. That is not a
     * simplification made here — `CONSULTANTS_MANAGE` is the only consultant
     * permission the platform has, so there is no create/moderate split to
     * mirror, and the creator of a listing has no standing over it that anyone
     * else with the permission lacks. Requirements differ per section across
     * this SDK because it mirrors Gurb exactly rather than unifying them.
     *
     * A plain member holds none of it: `MEMBER` is an empty permission set in
     * Gurb, so a key whose owner was granted nothing reads every listing and
     * publishes none. Correct, not a misconfiguration.
     *
     * `$bookingUrl` is required because the column is NOT NULL upstream.
     *
     * TYPES (specialties) ARE NOT CREATED HERE. Pass `typeIds` in `$options` to
     * attach EXISTING ones; there is no `newTypes`, because creating taxonomy
     * rows as a side effect of writing a consultant is how a shared specialty
     * vocabulary fills with near-duplicates.
     *
     * @param array<string, mixed> $options `bio`, `price`, `avatarUrl`,
     *                                      `bannerUrl`, `status`
     *                                      (`available` | `coming_soon` |
     *                                      `not_available`, default
     *                                      `available`), `typeIds`.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) with NO request made
     *                          when displayName or bookingUrl is blank.
     */
    public function create(string $displayName, string $bookingUrl, array $options = []): Consultant
    {
        $trimmedName = \trim($displayName);
        $trimmedUrl = \trim($bookingUrl);

        if ($trimmedName === '' || $trimmedUrl === '') {
            throw GurbApiException::localValidation(
                'A consultant needs a displayName and a bookingUrl.',
            );
        }

        // The trimmed values are what get SENT, not merely what got validated.
        $body = \array_merge($options, [
            'displayName' => $trimmedName,
            'bookingUrl' => $trimmedUrl,
        ]);

        return Consultant::fromArray(
            $this->requester->request('POST', 'sdk/consultants', body: $body),
        );
    }

    /**
     * Update a consultancy listing. `consultants:manage`, same as create.
     *
     * IMAGES CAN BE CLEARED HERE, unlike a group's: `avatarUrl: null` and
     * `bannerUrl: null` reach the column and empty it.
     *
     * `typeIds` REPLACES the specialty set wholesale — `[]` removes every one.
     * "Add a specialty" and "set the specialties" look identical in a request
     * body, so this is the sentence to read twice.
     *
     * @param array<string, mixed> $fields Only what you want changed:
     *                                     `displayName`, `bio`, `bookingUrl`,
     *                                     `price`, `avatarUrl`, `bannerUrl`,
     *                                     `status`, `isActive`, `typeIds`.
     *                                     An empty array is a 400, not a no-op.
     *
     * @throws GurbApiException
     */
    public function update(string $consultantId, array $fields): Consultant
    {
        return Consultant::fromArray($this->requester->request(
            'PATCH',
            // rawurlencode, so a slash inside a caller-supplied id cannot walk
            // the request onto a path this SDK never meant to call.
            'sdk/consultants/' . \rawurlencode($consultantId),
            body: $fields,
        ));
    }

    /**
     * Delete a consultancy listing. `consultants:manage`, same as create.
     *
     * A HARD delete — there is no `deletedAt` and nothing to restore. Where a
     * blog's `deleted: true` describes a soft delete, here it is literal.
     *
     * @throws GurbApiException
     */
    public function delete(string $consultantId): void
    {
        $this->requester->request('DELETE', 'sdk/consultants/' . \rawurlencode($consultantId));
    }
}
