<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\ContentInvitationsResult;

/**
 * «دعوة للمشاهدة» — invite members to look at a blog post, an album, an event, a post (`tweet`), a
 * project (`project` / `project2`, the two projects sections) or a consultant.
 *
 * The key's owner needs the item section's manage or moderate permission — the same rule as Gurb's
 * web app (403 INSUFFICIENT_PERMISSION otherwise; consultants: consultants:manage). The owner and
 * each invitee must also be able to open the item (others come back 'UNAVAILABLE').
 * A person is invited to an item ONCE, by anyone. The item's section must be on the plan
 * (403 FEATURE_NOT_AVAILABLE). 20 invitees per call; 100 invitations per user per day
 * (429 INVITES_DAILY_LIMIT, not retryable).
 *
 * Twin of `contentInvitations` in `@gurb/server` — same paths, bodies and local guards.
 */
final class ContentInvitationsResource
{
    private const TYPES = ['blog', 'album', 'event', 'tweet', 'project', 'project2', 'consultant'];

    private const CHANNELS = ['email', 'push', 'inApp'];

    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @param 'blog'|'album'|'event'|'tweet'|'project'|'project2'|'consultant' $resourceType
     * @param list<string>                         $inviteeIds User ids (not member ids), 1–20.
     * @param list<'email'|'push'|'inApp'>|null    $channels   null → the server default, push + bell.
     *
     * @throws GurbApiException
     */
    public function create(string $resourceType, string $resourceId, array $inviteeIds, ?string $message = null, ?array $channels = null): ContentInvitationsResult
    {
        self::assertType($resourceType);
        if ($inviteeIds === []) {
            throw GurbApiException::localValidation('An invitation needs at least one invitee.');
        }
        $body = ['resourceType' => $resourceType, 'resourceId' => $resourceId, 'inviteeIds' => \array_values($inviteeIds)];
        if ($message !== null) {
            $body['message'] = $message;
        }
        if ($channels !== null) {
            if ($channels === []) {
                throw GurbApiException::localValidation('An invitation needs at least one channel: "email", "push" or "inApp".');
            }
            foreach ($channels as $c) {
                if (!\in_array($c, self::CHANNELS, true)) {
                    throw GurbApiException::localValidation('Unknown invitation channel — use "email", "push" or "inApp".');
                }
            }
            $body['channels'] = \array_values($channels);
        }

        return ContentInvitationsResult::fromArray($this->requester->request('POST', 'sdk/content-invitations', body: $body));
    }

    /**
     * The user ids the key's owner already invited to this item.
     *
     * @param 'blog'|'album'|'event'|'tweet'|'project'|'project2'|'consultant' $resourceType
     *
     * @return list<string>
     *
     * @throws GurbApiException
     */
    public function listMine(string $resourceType, string $resourceId): array
    {
        self::assertType($resourceType);
        $data = $this->requester->request('GET', 'sdk/content-invitations/mine', [
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
        ]);
        $ids = $data['inviteeIds'] ?? [];

        return \is_array($ids) ? \array_values(\array_filter($ids, \is_string(...))) : [];
    }

    private static function assertType(string $type): void
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw GurbApiException::localValidation('resourceType must be one of "' . \implode('", "', self::TYPES) . '".');
        }
    }
}
