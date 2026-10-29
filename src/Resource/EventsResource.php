<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Event;
use Gurb\Model\EventReminderResult;
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

    /**
     * «إرسال تذكير» — remind people about the event now: the audience you pick, through the
     * channels you pick, with an optional message.
     *
     * $audience: 'going' (default — PAID ticket holders of a paid event, members «going» to a free
     * one), 'interested' (pressed «interested», not confirmed), 'all' (every approved member) or
     * 'members' (the 1–200 $userIds you pick; non-members among them are dropped).
     *
     * The key's owner needs `calendar:manage` OR `calendar:moderate` — the permission Gurb's web
     * app checks for the same button. One manual reminder per event per 10 minutes
     * (429 EVENT_REMINDER_COOLDOWN, not retryable); a draft, cancelled, finished or started event
     * is refused (409). E-mails are queued: `emailQueued` is not a delivery count.
     *
     * @param list<'email'|'push'|'inApp'>                $channels One, several or all three.
     * @param 'going'|'interested'|'all'|'members'        $audience
     * @param list<string>                                $userIds  Only with 'members'.
     * @param string|null                                 $message  Up to 300 characters.
     *
     * @throws GurbApiException
     */
    public function sendReminder(string $eventId, array $channels, string $audience = 'going', array $userIds = [], ?string $message = null): EventReminderResult
    {
        if ($channels === []) {
            throw GurbApiException::localValidation('A reminder needs at least one channel: "email", "push" or "inApp".');
        }
        foreach ($channels as $c) {
            if (!\in_array($c, ['email', 'push', 'inApp'], true)) {
                throw GurbApiException::localValidation('Unknown reminder channel — use "email", "push" or "inApp".');
            }
        }

        if (!\in_array($audience, ['going', 'interested', 'all', 'members'], true)) {
            throw GurbApiException::localValidation('Unknown reminder audience — use "going", "interested", "all" or "members".');
        }
        $body = ['channels' => \array_values($channels)];
        if ($audience !== 'going') {
            $body['audience'] = $audience;
        }
        if ($audience === 'members') {
            if ($userIds === []) {
                throw GurbApiException::localValidation('audience "members" needs at least one user id.');
            }
            $body['userIds'] = \array_values($userIds);
        }
        if ($message !== null) {
            $body['message'] = $message;
        }

        return EventReminderResult::fromArray($this->requester->request(
            'POST',
            'sdk/events/' . \rawurlencode($eventId) . '/reminders',
            body: $body,
        ));
    }
}
