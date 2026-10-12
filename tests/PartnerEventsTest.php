<?php

declare(strict_types=1);

namespace Gurb\Tests;

use DateTimeImmutable;
use Gurb\GurbApiException;
use Gurb\GurbErrorCode;
use Gurb\GurbPartnerEvents;
use Gurb\PartnerEvent;
use Gurb\PartnerSendResult;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PartnerEventsTest extends TestCase
{
    private const COMMUNITY = '00000000-0000-4000-8000-000000000001';
    private const SECRET = 'whsec_Z3VyYi1zZGstdGVzdC12ZWN0b3Itc2VjcmV0LTMyYnl0ZXMhIQ==';
    private const SUB = 'riyadah_46ed90e0612a692ced18fcb7f2711d6e422238e041639abd2b036566ba50ddd5';

    private function client(?StubHttpClient $http = null): GurbPartnerEvents
    {
        return new GurbPartnerEvents(self::COMMUNITY, self::SECRET, 'https://community.example.com/', $http ?? StubHttpClient::json(200, []));
    }

    /** @return array<string, mixed> */
    private static function body(PartnerEvent $event): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($event->body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    #[Test]
    public function signs_exactly_like_the_standardwebhooks_library_gurb_verifies_with(): void
    {
        // The same vector as the TypeScript SDK's test: produced with
        // `new Webhook(secret).sign(id, new Date(ts * 1000), body)` from the backend.
        $id = 'evt_0123456789abcdef0123456789abcdef';
        $body = '{"id":"evt_0123456789abcdef0123456789abcdef","type":"ping","created_at":"2026-10-12T00:00:00.000Z","community":"00000000-0000-4000-8000-000000000001","data":{"sub":null,"state_version":0,"message":"ping"}}';
        self::assertSame('v1,mO6Ip3mTmriAFclHsH7jq2M6KbaI2UCWOR1hqAXdouw=', GurbPartnerEvents::sign([self::SECRET], $id, 1790000000, $body));
    }

    #[Test]
    public function refuses_an_api_key_and_anything_that_is_not_an_events_secret(): void
    {
        foreach (['gurb_a1b2c3d4e5f6_' . \str_repeat('x', 43), '', 'whsec_', 'whsec_abc', 'whsec_' . \base64_encode(\str_repeat("\0", 8))] as $secret) {
            try {
                new GurbPartnerEvents(self::COMMUNITY, $secret, httpClient: StubHttpClient::json(200, []));
                self::fail("accepted {$secret}");
            } catch (GurbApiException $e) {
                self::assertSame([GurbErrorCode::VALIDATION_ERROR, 0], [$e->code(), $e->status()]);
            }
        }
        $this->expectException(GurbApiException::class);
        $this->expectExceptionCode(0); // status 0: refused locally, nothing was sent
        new GurbPartnerEvents('demo-riyadh-readers', self::SECRET, httpClient: StubHttpClient::json(200, []));
    }

    #[Test]
    public function builds_the_contract_envelope(): void
    {
        $event = $this->client()->accessChanged('  ' . self::SUB . '  ', 1790000400000, 'blocked');
        $body = self::body($event);
        self::assertMatchesRegularExpression('/^evt_[0-9a-f]{32}$/', $event->id);
        self::assertSame('access.changed', $body['type']);
        self::assertSame(self::COMMUNITY, $body['community']);
        self::assertSame($event->id, $body['id']);
        self::assertSame(['sub' => self::SUB, 'state_version' => 1790000400000, 'access' => 'blocked'], $body['data']);

        $updated = self::body($this->client()->memberUpdated(self::SUB, 7, ' سارة   العتيبي ', 'sara@example.com', 'https://cdn.example.com/a.png', 'ar'));
        self::assertSame(['sub' => self::SUB, 'state_version' => 7, 'name' => 'سارة العتيبي', 'email' => 'sara@example.com', 'avatar_url' => 'https://cdn.example.com/a.png', 'locale' => 'ar'], $updated['data']);
        // Omitted = empty on Gurb (a full replacement): nothing is sent for them.
        self::assertSame(['sub' => self::SUB, 'state_version' => 8], self::body($this->client()->memberUpdated(self::SUB, 8, null, ''))['data']);

        $deleted = self::body($this->client()->memberDeleted(self::SUB, 9, new DateTimeImmutable('2026-10-07T08:00:00Z')));
        self::assertSame(['sub' => self::SUB, 'state_version' => 9, 'requested_at' => '2026-10-07T08:00:00.000Z'], $deleted['data']);
        self::assertSame(['sub' => null, 'state_version' => 0, 'message' => 'ping'], self::body($this->client()->ping())['data']);
    }

    /** @return iterable<string, array{callable(GurbPartnerEvents): mixed}> */
    public static function invalidInputs(): iterable
    {
        yield 'empty sub' => [fn (GurbPartnerEvents $c) => $c->accessChanged('  ', 1, 'allowed')];
        yield 'sub over 255' => [fn (GurbPartnerEvents $c) => $c->accessChanged(\str_repeat('x', 256), 1, 'allowed')];
        yield 'NUL in a sub' => [fn (GurbPartnerEvents $c) => $c->accessChanged("riyadah_\0", 1, 'allowed')];
        yield 'invalid UTF-8 in a name' => [fn (GurbPartnerEvents $c) => $c->memberUpdated(self::SUB, 1, "Sara \xC3")];
        yield 'negative version' => [fn (GurbPartnerEvents $c) => $c->accessChanged(self::SUB, -1, 'allowed')];
        yield 'unknown access' => [fn (GurbPartnerEvents $c) => $c->accessChanged(self::SUB, 1, 'suspended')];
        yield 'name over 200' => [fn (GurbPartnerEvents $c) => $c->memberUpdated(self::SUB, 1, \str_repeat('x', 201))];
        yield 'not an email' => [fn (GurbPartnerEvents $c) => $c->memberUpdated(self::SUB, 1, email: 'not-an-email')];
        yield 'a Gurb placeholder address' => [fn (GurbPartnerEvents $c) => $c->memberUpdated(self::SUB, 1, email: 'x@c.embed.invalid')];
        yield 'an http photo' => [fn (GurbPartnerEvents $c) => $c->memberUpdated(self::SUB, 1, avatarUrl: 'http://cdn.example.com/a.png')];
        yield 'a photo on another port' => [fn (GurbPartnerEvents $c) => $c->memberUpdated(self::SUB, 1, avatarUrl: 'https://cdn.example.com:8443/a.png')];
    }

    #[Test]
    #[DataProvider('invalidInputs')]
    public function builders_refuse_what_gurb_would_refuse(callable $build): void
    {
        $this->expectException(GurbApiException::class);
        $this->expectExceptionCode(0); // status 0: refused locally, nothing was sent
        $build($this->client());
    }

    #[Test]
    public function snapshot_splits_into_pages_sharing_one_id_and_time(): void
    {
        $members = [];
        for ($i = 0; $i < 1201; $i++) {
            $members[] = ['sub' => "riyadah_{$i}", 'stateVersion' => 1000 + $i, 'access' => $i % 7 === 0 ? 'blocked' : 'allowed'];
        }
        $at = new DateTimeImmutable('-1 minute');
        $pages = $this->client()->snapshot($members, generatedAt: $at);
        self::assertCount(3, $pages);
        $bodies = \array_map(static fn (PartnerEvent $p): array => self::body($p)['data'], $pages);
        self::assertCount(1, \array_unique(\array_column($bodies, 'snapshot_id')));
        self::assertSame([1, 2, 3], \array_column($bodies, 'page'));
        self::assertSame([3, 3, 3], \array_column($bodies, 'pages'));
        self::assertSame([500, 500, 201], \array_map(static fn (array $b): int => \count($b['members']), $bodies));

        $empty = $this->client()->snapshot([]);
        self::assertCount(1, $empty);
        self::assertSame([], self::body($empty[0])['data']['members']);
    }

    #[Test]
    public function snapshot_refuses_duplicates_and_bad_times(): void
    {
        $c = $this->client();
        $m = ['sub' => 'riyadah_1', 'stateVersion' => 1, 'access' => 'allowed'];
        foreach ([
            fn () => $c->snapshot([$m, $m]),
            fn () => $c->snapshot([], generatedAt: new DateTimeImmutable('+10 minutes')),
            fn () => $c->snapshot([], generatedAt: new DateTimeImmutable('-73 hours')),
            fn () => $c->snapshot([], snapshotId: 'has space'),
            fn () => $c->snapshot([], pageSize: 501),
        ] as $build) {
            try {
                $build();
                self::fail('accepted');
            } catch (GurbApiException $e) {
                self::assertSame([GurbErrorCode::VALIDATION_ERROR, 0], [$e->code(), $e->status()]);
            }
        }
    }

    #[Test]
    public function send_posts_the_stored_body_with_fresh_standard_webhooks_headers(): void
    {
        $http = StubHttpClient::json(200, ['success' => true, 'outcome' => 'applied']);
        $c = $this->client($http);
        $event = $c->accessChanged(self::SUB, 1, 'allowed');
        $result = $c->send(PartnerEvent::fromArray(\json_decode(\json_encode($event->toArray(), \JSON_THROW_ON_ERROR), true)));

        self::assertSame(PartnerSendResult::DELIVERED, $result->outcome);
        self::assertSame('applied', $result->applied);
        $request = $http->calls[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://community.example.com/api/webhooks/inbound/' . self::COMMUNITY . '/host', $request->url);
        self::assertSame($event->body, $request->body);
        self::assertSame($event->id, $request->headers['webhook-id']);
        $ts = (int) $request->headers['webhook-timestamp'];
        self::assertLessThan(5, \abs($ts - \time()));
        self::assertSame(GurbPartnerEvents::sign([self::SECRET], $event->id, $ts, $event->body), $request->headers['webhook-signature']);
    }

    /** @return iterable<string, array{int, array<string, mixed>, string}> */
    public static function statuses(): iterable
    {
        yield '200 duplicate' => [200, ['success' => true, 'outcome' => 'duplicate'], PartnerSendResult::DELIVERED];
        yield '400' => [400, ['code' => 'HOST_EVENT_INVALID'], PartnerSendResult::REJECTED];
        yield '401' => [401, ['code' => 'WEBHOOK_SIGNATURE_INVALID'], PartnerSendResult::REJECTED];
        yield '404' => [404, ['code' => 'WEBHOOK_PROVIDER_UNKNOWN'], PartnerSendResult::REJECTED];
        yield '408' => [408, [], PartnerSendResult::RETRY];
        yield '429' => [429, [], PartnerSendResult::RETRY];
        yield '503' => [503, ['code' => 'HOST_EVENT_NOT_YET_SUPPORTED'], PartnerSendResult::RETRY];
    }

    /** @param array<string, mixed> $body */
    #[Test]
    #[DataProvider('statuses')]
    public function send_classifies_every_status(int $status, array $body, string $outcome): void
    {
        $c = $this->client(StubHttpClient::json($status, $body));
        $result = $c->send($c->ping());
        self::assertSame($outcome, $result->outcome);
        self::assertSame($status, $result->status);
        if (isset($body['code'])) {
            self::assertSame($body['code'], $result->code);
        }
    }

    #[Test]
    public function a_network_failure_is_a_retry_never_a_throw(): void
    {
        $c = $this->client(StubHttpClient::failing());
        $result = $c->send($c->ping());
        self::assertSame(PartnerSendResult::RETRY, $result->outcome);
        self::assertSame(0, $result->status);
    }

    #[Test]
    public function refuses_an_event_built_for_another_community(): void
    {
        $theirs = new GurbPartnerEvents('00000000-0000-4000-8000-000000000002', self::SECRET, httpClient: StubHttpClient::json(200, []));
        $this->expectException(GurbApiException::class);
        $this->expectExceptionCode(0); // status 0: refused locally, nothing was sent
        $this->client()->send($theirs->ping());
    }

    /** @return iterable<string, array{array<string, mixed>, int, string}> */
    public static function refusals(): iterable
    {
        yield 'error field' => [['error' => 'HOST_MEMBER_BLOCKED'], 403, GurbErrorCode::HOST_MEMBER_BLOCKED];
        yield 'code field only' => [['code' => 'JOIN_THROUGH_HOST_ONLY', 'message' => 'host only'], 403, GurbErrorCode::JOIN_THROUGH_HOST_ONLY];
        yield 'member not active' => [['error' => 'MEMBER_NOT_ACTIVE'], 409, GurbErrorCode::MEMBER_NOT_ACTIVE];
        yield 'unknown code stays generic' => [['error' => 'Some new text', 'code' => 'SOMETHING_UNKNOWN'], 403, GurbErrorCode::FORBIDDEN_SURFACE];
    }

    /** @param array<string, mixed> $body */
    #[Test]
    #[DataProvider('refusals')]
    public function the_new_refusal_codes_reach_the_exception(array $body, int $status, string $code): void
    {
        self::assertSame($code, GurbApiException::fromResponse($status, $body)->code());
    }
}
