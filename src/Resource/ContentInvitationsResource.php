<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\ContentInvitationsResult;

/**
 * «دعوة للمشاهدة» — invite members to look at a blog post, an album or an event.
 *
 * No permission is needed — the same rule as Gurb's web app: any member who can open the item
 * may invite, and each invitee must be able to open it too (others come back 'UNAVAILABLE').
 * A person is invited to an item ONCE, by anyone. The item's section must be on the plan
 * (403 FEATURE_NOT_AVAILABLE). 20 invitees per call; 100 invitations per user per day
 * (429 INVITES_DAILY_LIMIT, not retryable).
 *
 * Twin of `contentInvitations` in `@gurb/server` — same paths, bodies and local guards.
 */
final class ContentInvitationsResource
{
    private const TYPES = ['blog', 'album', 'event'];

    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @param 'blog'|'album'|'event' $resourceType
     * @param list<string>           $inviteeIds User ids (not member ids), 1–20.
     *
     * @throws GurbApiException
     */
    public function create(string $resourceType, string $resourceId, array $inviteeIds, ?string $message = null): ContentInvitationsResult
    {
        self::assertType($resourceType);
        if ($inviteeIds === []) {
            throw GurbApiException::localValidation('An invitation needs at least one invitee.');
        }
        $body = ['resourceType' => $resourceType, 'resourceId' => $resourceId, 'inviteeIds' => \array_values($inviteeIds)];
        if ($message !== null) {
            $body['message'] = $message;
        }

        return ContentInvitationsResult::fromArray($this->requester->request('POST', 'sdk/content-invitations', body: $body));
    }

    /**
     * The user ids the key's owner already invited to this item.
     *
     * @param 'blog'|'album'|'event' $resourceType
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
            throw GurbApiException::localValidation('resourceType must be "blog", "album" or "event".');
        }
    }
}
