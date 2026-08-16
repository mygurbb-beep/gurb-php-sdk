<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Event;
use Gurb\Model\Paginated;

final class EventsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @param bool|null $upcoming Null means "let the server decide" — which is
     *                            not the same as false, so it is not defaulted.
     *
     * @return Paginated<Event>
     *
     * @throws GurbApiException
     */
    public function list(?int $page = null, ?int $limit = null, ?bool $upcoming = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/events', [
                'page' => $page,
                'limit' => $limit,
                'upcoming' => $upcoming,
            ]),
            Event::fromArray(...),
        );
    }

    /**
     * Create a event.
     *
     * Requires the `events` feature on the community's plan. A community without
     * it answers 403 FEATURE_NOT_AVAILABLE on EVERY call here, reads included —
     * that is an entitlement, not a permission, and no grant will fix it.
     *
     * The key's owner needs `calendar:manage` OR `calendar:create`. Every write in this SDK uses the SAME
     * permission Gurb's own web app checks — never stricter, never looser — so
     * the requirement differs per section and cannot be unified. A plain member
     * holds none of these: in Gurb `MEMBER` is an empty permission set and
     * publishing is a capability an admin grants. A key whose owner was granted
     * nothing reads everything and publishes nothing, which is correct rather
     * than a misconfiguration.
     *
     * `status: 'DRAFT'` announces NOTHING — no webhook, no notification.
     * That is inherited from Gurb and is deliberate: an integrator should not be
     * told "an event happened" about a draft.
     *
     * @param array<string, mixed> $options Any other field the endpoint accepts.
     *                                      Unknown keys are ignored by the server.
     *
     * @throws GurbApiException
     */
    public function create(
        string $title,
        string $titleArabic,
        string $startDate,
        string $endDate,
        array $options = [],
    ): Event {
        $body = \array_merge($options, [
            'title' => $title,
            // Required, not optional. Community-internal pages are Arabic-only
            // by design, so an event with no Arabic title renders blank there.
            'titleArabic' => $titleArabic,
            // ISO 8601 strings. Sent as given; the server parses and validates.
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);

        return Event::fromArray($this->requester->request('POST', 'sdk/events', body: $body));
    }

    /**
     * Update a event.
     *
     * The key's owner may ALWAYS edit what it created — authorship is checked
     * before permissions, exactly as the web app checks it. Editing anyone
     * else's needs the section's `*:manage` or `*:moderate`, so expect 403s on
     * content your key did not create.
     *
     * @param array<string, mixed> $fields Only what you want changed.
     *
     * @throws GurbApiException
     */
    public function update(string $eventId, array $fields): Event
    {
        return Event::fromArray($this->requester->request(
            'PATCH',
            // rawurlencode, so a slash inside a caller-supplied id cannot walk
            // the request onto a path this SDK never meant to call.
            'sdk/events/' . \rawurlencode($eventId),
            body: $fields,
        ));
    }

    /**
     * Delete a event. Same authorship-or-moderation rule as update().
     *
     * @throws GurbApiException
     */
    public function delete(string $eventId): void
    {
        $this->requester->request('DELETE', 'sdk/events/' . \rawurlencode($eventId));
    }
}
