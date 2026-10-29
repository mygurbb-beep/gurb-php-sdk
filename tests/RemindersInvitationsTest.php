<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\GurbErrorCode;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Event reminders and content invitations (1.9.0) — the wire contract, asserted.
 *
 * Twin of the TypeScript SDK's `event reminders + content invitations (1.9.0)` suite: the same
 * paths, bodies and error codes. Change both in the same commit.
 */
final class RemindersInvitationsTest extends TestCase
{
    private const KEY = 'gurb_a1b2c3d4e5f6_' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    private function client(int $status, array $body, ?StubHttpClient &$http = null): GurbClient
    {
        $http = StubHttpClient::json($status, $body);

        return new GurbClient(self::KEY, 'https://x.test', $http);
    }

    #[Test]
    public function send_reminder_posts_the_channels_to_the_event(): void
    {
        $data = ['recipients' => 2, 'channels' => ['push', 'inApp'], 'inApp' => 2, 'push' => 2, 'emailQueued' => 0];
        $result = $this->client(200, ['success' => true, 'data' => $data], $http)->events->sendReminder('ev 1', ['push', 'inApp']);

        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/events/ev%201/reminders', $http->lastCall()->url);
        self::assertSame(['channels' => ['push', 'inApp']], \json_decode((string) $http->lastCall()->body, true));
        self::assertSame(2, $result->recipients);
        self::assertSame(['push', 'inApp'], $result->channels);
        self::assertSame(0, $result->emailQueued);
    }

    #[Test]
    public function an_empty_or_unknown_channel_is_refused_before_any_request(): void
    {
        $client = $this->client(200, ['success' => true, 'data' => []], $http);
        foreach ([[], ['sms']] as $channels) {
            try {
                $client->events->sendReminder('e1', $channels);
                self::fail('expected a local refusal');
            } catch (GurbApiException $e) {
                self::assertSame(GurbErrorCode::VALIDATION_ERROR, $e->code());
            }
        }
        self::assertCount(0, $http->calls);
    }

    #[Test]
    public function a_reminder_cooldown_is_its_own_code_and_not_retryable(): void
    {
        $client = $this->client(429, ['success' => false, 'code' => 'EVENT_REMINDER_COOLDOWN', 'message' => 'sent a few minutes ago']);
        try {
            $client->events->sendReminder('e1', ['email']);
            self::fail('expected the cooldown');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::EVENT_REMINDER_COOLDOWN, $e->code());
            self::assertSame(429, $e->status());
            self::assertFalse($e->isRetryable());
        }
    }

    #[Test]
    public function the_409_refusals_map_to_stable_codes(): void
    {
        foreach (['EVENT_NOT_REMINDABLE', 'EVENT_ALREADY_STARTED', 'EVENT_REMINDER_NO_RECIPIENTS'] as $code) {
            try {
                $this->client(409, ['success' => false, 'code' => $code, 'message' => 'refused'])->events->sendReminder('e1', ['inApp']);
                self::fail('expected ' . $code);
            } catch (GurbApiException $e) {
                self::assertSame($code, $e->code());
            }
        }
    }

    #[Test]
    public function create_invitations_posts_the_request_and_returns_invited_and_skipped(): void
    {
        $data = ['invited' => ['u2'], 'skipped' => [['userId' => 'u3', 'reason' => 'UNAVAILABLE']]];
        $result = $this->client(200, ['success' => true, 'data' => $data], $http)
            ->contentInvitations->create('album', 'a1', ['u2', 'u3'], 'شوفوا');

        self::assertSame('https://x.test/api/sdk/content-invitations', $http->lastCall()->url);
        self::assertSame(
            ['resourceType' => 'album', 'resourceId' => 'a1', 'inviteeIds' => ['u2', 'u3'], 'message' => 'شوفوا'],
            \json_decode((string) $http->lastCall()->body, true),
        );
        self::assertSame(['u2'], $result->invited);
        self::assertSame([['userId' => 'u3', 'reason' => 'UNAVAILABLE']], $result->skipped);
    }

    #[Test]
    public function list_mine_sends_the_item_in_the_query(): void
    {
        $ids = $this->client(200, ['success' => true, 'data' => ['inviteeIds' => ['u2']]], $http)
            ->contentInvitations->listMine('blog', 'b1');

        self::assertSame('https://x.test/api/sdk/content-invitations/mine?resourceType=blog&resourceId=b1', $http->lastCall()->url);
        self::assertSame(['u2'], $ids);
    }

    #[Test]
    public function an_unknown_section_is_refused_before_any_request(): void
    {
        $client = $this->client(200, ['success' => true, 'data' => []], $http);
        try {
            $client->contentInvitations->create('group', 'g1', ['u2']);
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::VALIDATION_ERROR, $e->code());
        }
        self::assertCount(0, $http->calls);
    }

    #[Test]
    public function the_daily_invitation_limit_is_its_own_code_and_not_retryable(): void
    {
        try {
            $this->client(429, ['success' => false, 'code' => 'INVITES_DAILY_LIMIT', 'message' => 'limit'])
                ->contentInvitations->create('blog', 'b1', ['u2']);
            self::fail('expected the daily limit');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::INVITES_DAILY_LIMIT, $e->code());
            self::assertFalse($e->isRetryable());
        }
    }

    #[Test]
    public function send_reminder_sends_the_audience_the_picked_members_and_the_message(): void
    {
        $data = ['recipients' => 1, 'audience' => 'members', 'channels' => ['inApp'], 'inApp' => 1, 'push' => 0, 'emailQueued' => 0];
        $result = $this->client(200, ['success' => true, 'data' => $data], $http)
            ->events->sendReminder('e1', ['inApp'], 'members', ['u2'], 'لا تنسون');

        self::assertSame(
            ['channels' => ['inApp'], 'audience' => 'members', 'userIds' => ['u2'], 'message' => 'لا تنسون'],
            \json_decode((string) $http->lastCall()->body, true),
        );
        self::assertSame('members', $result->audience);
    }

    #[Test]
    public function an_unknown_audience_or_members_without_ids_is_refused_before_any_request(): void
    {
        $client = $this->client(200, ['success' => true, 'data' => []], $http);
        foreach ([['everyone', []], ['members', []]] as [$audience, $ids]) {
            try {
                $client->events->sendReminder('e1', ['inApp'], $audience, $ids);
                self::fail('expected a local refusal');
            } catch (GurbApiException $e) {
                self::assertSame(GurbErrorCode::VALIDATION_ERROR, $e->code());
            }
        }
        self::assertCount(0, $http->calls);
    }

    #[Test]
    public function the_audience_refusals_map_to_stable_codes(): void
    {
        foreach ([GurbErrorCode::REMINDER_AUDIENCE_INVALID, GurbErrorCode::REMINDER_MEMBERS_REQUIRED, GurbErrorCode::REMINDER_MEMBERS_TOO_MANY, GurbErrorCode::REMINDER_MESSAGE_TOO_LONG] as $code) {
            try {
                $this->client(400, ['success' => false, 'code' => $code, 'message' => 'refused'])->events->sendReminder('e1', ['inApp'], 'all');
                self::fail('expected ' . $code);
            } catch (GurbApiException $e) {
                self::assertSame($code, $e->code());
            }
        }
    }

    #[Test]
    public function create_invitations_sends_the_new_sections_and_the_channels(): void
    {
        $data = ['invited' => ['u2'], 'skipped' => [], 'channels' => ['email', 'inApp'], 'emailQueued' => 1];
        $result = $this->client(200, ['success' => true, 'data' => $data], $http)
            ->contentInvitations->create('project2', 'p1', ['u2'], null, ['email', 'inApp']);

        self::assertSame(
            ['resourceType' => 'project2', 'resourceId' => 'p1', 'inviteeIds' => ['u2'], 'channels' => ['email', 'inApp']],
            \json_decode((string) $http->lastCall()->body, true),
        );
        self::assertSame(['email', 'inApp'], $result->channels);
        self::assertSame(1, $result->emailQueued);
    }

    #[Test]
    public function an_empty_or_unknown_invitation_channel_is_refused_before_any_request(): void
    {
        $client = $this->client(200, ['success' => true, 'data' => []], $http);
        foreach ([[], ['sms']] as $channels) {
            try {
                $client->contentInvitations->create('tweet', 't1', ['u2'], null, $channels);
                self::fail('expected a local refusal');
            } catch (GurbApiException $e) {
                self::assertSame(GurbErrorCode::VALIDATION_ERROR, $e->code());
            }
        }
        self::assertCount(0, $http->calls);
    }

    #[Test]
    public function every_new_section_is_accepted_locally(): void
    {
        foreach (['tweet', 'project', 'project2', 'consultant'] as $type) {
            $this->client(200, ['success' => true, 'data' => ['invited' => [], 'skipped' => []]], $http)
                ->contentInvitations->create($type, 'x1', ['u2']);
            self::assertSame($type, \json_decode((string) $http->lastCall()->body, true)['resourceType']);
        }
    }
}
