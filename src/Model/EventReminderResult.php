<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * What `events->sendReminder()` did — «إرسال تذكير».
 *
 * Same shape as `EventReminderResult` in `@gurb/api-types`.
 */
final class EventReminderResult
{
    private function __construct(
        /** People addressed in the chosen audience — never the key's owner. */
        public readonly int $recipients,
        /** 'going' | 'interested' | 'all' | 'members' — 'going' when the request omitted it. */
        public readonly string $audience,
        /** @var list<string> 'email' | 'push' | 'inApp', in that fixed order. */
        public readonly array $channels,
        /** Bell notifications stored (0 without 'inApp'). */
        public readonly int $inApp,
        /** Attendees whose push was handed to FCM (0 without 'push'). */
        public readonly int $push,
        /** E-mails QUEUED — sent in the background, a few per second. Not a delivery count. */
        public readonly int $emailQueued,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            recipients: Decode::int($data, 'recipients'),
            audience: Decode::str($data, 'audience', 'going'),
            channels: Decode::strList($data, 'channels'),
            inApp: Decode::int($data, 'inApp'),
            push: Decode::int($data, 'push'),
            emailQueued: Decode::int($data, 'emailQueued'),
        );
    }
}
