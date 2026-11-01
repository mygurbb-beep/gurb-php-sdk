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
 * Event registrations (1.10.0) — «تسجيل الزوار في الفعاليات», the organiser's surface.
 *
 * Twin of the TypeScript SDK's `event registrations (1.10.0)` suite: the same paths, bodies,
 * local guards and error codes. Change both in the same commit.
 */
final class EventRegistrationsTest extends TestCase
{
    private const KEY = 'gurb_a1b2c3d4e5f6_' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    private function client(int $status, array $body, ?StubHttpClient &$http = null): GurbClient
    {
        $http = StubHttpClient::json($status, $body);

        return new GurbClient(self::KEY, 'https://x.test', $http);
    }

    #[Test]
    public function list_registrations_gets_the_event_registrations_with_the_status_filter(): void
    {
        $data = [
            'items' => [[
                'userId' => 'u2',
                'name' => 'سارة القحطاني',
                'email' => 'sara@example.com',
                'avatar' => null,
                'isGuest' => true,
                'status' => 'PENDING',
                'answers' => ['q1' => 'نعم', 'q2' => ['optionId' => 'o1']],
                'createdAt' => '2026-10-29T08:00:00.000Z',
                'decidedAt' => null,
                'declineReason' => null,
            ]],
            'counts' => ['PENDING' => 1, 'GOING' => 4, 'DECLINED' => 0],
        ];
        $list = $this->client(200, ['success' => true, 'data' => $data], $http)->events->listRegistrations('ev 1', 'ALL');

        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/events/ev%201/registrations?status=ALL', $http->lastCall()->url);
        self::assertCount(1, $list->items);
        $r = $list->items[0];
        self::assertSame('u2', $r->userId);
        self::assertSame('سارة القحطاني', $r->name);
        self::assertSame('sara@example.com', $r->email);
        self::assertNull($r->avatar);
        self::assertTrue($r->isGuest);
        self::assertSame('PENDING', $r->status);
        self::assertSame(['q1' => 'نعم', 'q2' => ['optionId' => 'o1']], $r->answers);
        self::assertSame('2026-10-29T08:00:00.000Z', $r->createdAt);
        self::assertNull($r->decidedAt);
        self::assertSame(['PENDING' => 1, 'GOING' => 4, 'DECLINED' => 0], $list->counts);
    }

    #[Test]
    public function list_registrations_drops_the_filter_when_it_is_not_given(): void
    {
        $this->client(200, ['success' => true, 'data' => ['items' => [], 'counts' => ['PENDING' => 0, 'GOING' => 0, 'DECLINED' => 0]]], $http)
            ->events->listRegistrations('e1');

        self::assertSame('https://x.test/api/sdk/events/e1/registrations', $http->lastCall()->url);
    }

    #[Test]
    public function an_unknown_status_filter_is_refused_before_any_request(): void
    {
        $client = $this->client(200, ['success' => true, 'data' => []], $http);
        try {
            $client->events->listRegistrations('e1', 'WAITING');
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::VALIDATION_ERROR, $e->code());
            self::assertSame(0, $e->status());
        }
        self::assertCount(0, $http->calls);
    }

    #[Test]
    public function approve_registration_posts_to_the_person_under_the_event_with_no_body(): void
    {
        $status = $this->client(200, ['success' => true, 'data' => ['status' => 'GOING']], $http)
            ->events->approveRegistration('e1', 'u/2');

        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/events/e1/registrations/u%2F2/approve', $http->lastCall()->url);
        self::assertNull($http->lastCall()->body);
        self::assertSame('GOING', $status);
    }

    #[Test]
    public function decline_registration_posts_the_reason_as_given(): void
    {
        $status = $this->client(200, ['success' => true, 'data' => ['status' => 'DECLINED']], $http)
            ->events->declineRegistration('e1', 'u2', 'اكتمل العدد');

        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/events/e1/registrations/u2/decline', $http->lastCall()->url);
        self::assertSame(['reason' => 'اكتمل العدد'], \json_decode((string) $http->lastCall()->body, true));
        self::assertSame('DECLINED', $status);
    }

    #[Test]
    public function decline_registration_without_a_reason_sends_no_body(): void
    {
        $this->client(200, ['success' => true, 'data' => ['status' => 'DECLINED']], $http)
            ->events->declineRegistration('e1', 'u2');

        self::assertNull($http->lastCall()->body);
    }

    #[Test]
    public function the_decision_refusals_map_to_stable_codes_none_retryable(): void
    {
        foreach ([[400, 'EVENT_FULL'], [409, 'REGISTRATION_NOT_PENDING'], [409, 'EVENT_NOT_BOOKABLE']] as [$status, $code]) {
            try {
                $this->client($status, ['success' => false, 'code' => $code, 'message' => 'refused'])->events->approveRegistration('e1', 'u2');
                self::fail('expected ' . $code);
            } catch (GurbApiException $e) {
                self::assertSame($code, $e->code());
                self::assertSame($status, $e->status());
                self::assertFalse($e->isRetryable());
            }
        }
        try {
            $this->client(409, ['success' => false, 'code' => 'REGISTRATION_NOT_PENDING', 'message' => 'refused'])->events->declineRegistration('e1', 'u2');
            self::fail('expected REGISTRATION_NOT_PENDING');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::REGISTRATION_NOT_PENDING, $e->code());
        }
    }

    #[Test]
    public function create_sends_the_registration_settings_and_questions_as_given(): void
    {
        $questions = ['sections' => [[
            'id' => 's1',
            'title' => ['ar' => 'عنك'],
            'questions' => [['id' => 'q1', 'type' => 'short_text', 'label' => ['ar' => 'جهة العمل'], 'required' => true, 'maxLength' => 200]],
        ]]];
        $event = [
            'id' => 'e1', 'title' => 'Book club', 'startsAt' => '2026-11-01T17:00:00.000Z', 'attendeeCount' => 0,
            'guestRegistration' => true, 'approvalGuests' => true, 'approvalMembers' => false, 'registrationQuestions' => $questions,
        ];
        $created = $this->client(201, ['success' => true, 'data' => $event], $http)->events->create(
            'Book club',
            'نادي الكتاب',
            '2026-11-01T17:00:00.000Z',
            '2026-11-01T19:00:00.000Z',
            ['guestRegistration' => true, 'approvalGuests' => true, 'approvalMembers' => false, 'registrationQuestions' => $questions],
        );

        $body = \json_decode((string) $http->lastCall()->body, true);
        self::assertTrue($body['guestRegistration']);
        self::assertTrue($body['approvalGuests']);
        self::assertFalse($body['approvalMembers']);
        self::assertSame($questions, $body['registrationQuestions']);
        self::assertTrue($created->guestRegistration);
        self::assertTrue($created->approvalGuests);
        self::assertFalse($created->approvalMembers);
        self::assertSame($questions, $created->registrationQuestions);
    }

    #[Test]
    public function an_event_without_the_settings_reads_them_as_null(): void
    {
        $event = $this->client(200, ['success' => true, 'data' => ['id' => 'e1', 'title' => 't', 'startsAt' => 'x', 'attendeeCount' => 0]])
            ->events->update('e1', ['title' => 't']);

        self::assertNull($event->guestRegistration);
        self::assertNull($event->approvalGuests);
        self::assertNull($event->approvalMembers);
        self::assertNull($event->registrationQuestions);
    }

    #[Test]
    public function update_can_clear_the_questions_with_null(): void
    {
        $this->client(200, ['success' => true, 'data' => ['id' => 'e1']], $http)
            ->events->update('e1', ['registrationQuestions' => null, 'approvalMembers' => false]);

        self::assertSame(
            ['registrationQuestions' => null, 'approvalMembers' => false],
            \json_decode((string) $http->lastCall()->body, true),
        );
    }

    #[Test]
    public function the_free_events_only_approval_refusal_maps_to_a_stable_code(): void
    {
        foreach ([GurbErrorCode::APPROVAL_REQUIRES_FREE_EVENT] as $code) {
            try {
                $this->client(400, ['success' => false, 'code' => $code, 'message' => 'free events only'])
                    ->events->update('e1', ['approvalGuests' => true]);
                self::fail('expected ' . $code);
            } catch (GurbApiException $e) {
                self::assertSame($code, $e->code());
                self::assertSame(400, $e->status());
            }
        }
    }
}
