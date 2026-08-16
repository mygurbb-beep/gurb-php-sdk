<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Publishing content — the wire contract, asserted.
 *
 * WHY THESE ASSERTIONS AND NOT "does it work"
 *
 * Every method here is a PUBLISHED CONTRACT. Once a customer's integration calls
 * it, changing a path or a verb breaks their production silently, at a moment we
 * do not choose and cannot roll back for them. Pinning the verb and the URL in a
 * test means such a change fails here instead of there.
 *
 * These assertions are deliberately IDENTICAL to the ones in the TypeScript
 * SDK's `publishing content` suite. The two libraries describe ONE contract, and
 * the only thing that stops them drifting apart is that both are pinned to the
 * same claims. If you change one file, change the other in the same commit.
 */
final class ContentWritesTest extends TestCase
{
    private const KEY = 'gurb_a1b2c3d4e5f6_' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    private function clientReturning(array $data, ?StubHttpClient &$http = null): GurbClient
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => $data]);

        return new GurbClient(self::KEY, 'https://x.test', $http);
    }

    // ─── Verb and path ───────────────────────────────────────────────────────

    #[Test]
    public function creating_a_post_is_a_post_to_the_tweets_path(): void
    {
        $client = $this->clientReturning($this->tweetRow(), $http);
        $client->tweets->create('مرحباً بالجميع');

        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/tweets', $http->lastCall()->url);
    }

    #[Test]
    #[DataProvider('createPaths')]
    public function each_section_creates_at_its_own_path(string $section, string $path): void
    {
        $client = $this->clientReturning($this->rowFor($section), $http);

        match ($section) {
            'events' => $client->events->create('T', 'ت', '2026-09-01T10:00:00Z', '2026-09-01T12:00:00Z'),
            'blogs' => $client->blogs->create('T', '<p>x</p>'),
            'albums' => $client->albums->create('T'),
        };

        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame("https://x.test/api/{$path}", $http->lastCall()->url);
    }

    public static function createPaths(): array
    {
        return [
            'events' => ['events', 'sdk/events'],
            'blogs' => ['blogs', 'sdk/blogs'],
            'albums' => ['albums', 'sdk/albums'],
        ];
    }

    #[Test]
    #[DataProvider('updatablePaths')]
    public function update_is_a_patch_and_delete_is_a_delete(string $section, string $path): void
    {
        // PATCH and not PUT on every section: each update is partial in fact,
        // and PATCH is what the members surface shipped with first. A library
        // that sent PUT here would look correct and quietly full-replace rows.
        $client = $this->clientReturning($this->rowFor($section), $http);

        match ($section) {
            'tweets' => $client->tweets->update('abc', 'new'),
            'events' => $client->events->update('abc', ['title' => 'new']),
            'blogs' => $client->blogs->update('abc', ['title' => 'new']),
            'albums' => $client->albums->update('abc', ['title' => 'new']),
        };
        self::assertSame('PATCH', $http->lastCall()->method);
        self::assertSame("https://x.test/api/{$path}/abc", $http->lastCall()->url);

        match ($section) {
            'tweets' => $client->tweets->delete('abc'),
            'events' => $client->events->delete('abc'),
            'blogs' => $client->blogs->delete('abc'),
            'albums' => $client->albums->delete('abc'),
        };
        self::assertSame('DELETE', $http->lastCall()->method);
        self::assertSame("https://x.test/api/{$path}/abc", $http->lastCall()->url);
    }

    public static function updatablePaths(): array
    {
        return [
            'tweets' => ['tweets', 'sdk/tweets'],
            'events' => ['events', 'sdk/events'],
            'blogs' => ['blogs', 'sdk/blogs'],
            'albums' => ['albums', 'sdk/albums'],
        ];
    }

    // ─── Ids are caller input ────────────────────────────────────────────────

    #[Test]
    public function an_id_containing_a_slash_cannot_walk_onto_another_route(): void
    {
        // An id comes from the caller. Unencoded, "a/b" turns
        // DELETE sdk/tweets/a/b into a path this SDK never meant to call —
        // which, on this backend, would not even 404: unmatched /api/* falls
        // into root-mounted routers and answers 400 "Tenant context required",
        // sending the integrator to debug credentials for a bad id.
        $client = $this->clientReturning([], $http);
        $client->tweets->delete('a/b');

        self::assertSame('https://x.test/api/sdk/tweets/a%2Fb', $http->lastCall()->url);
    }

    // ─── Local refusals ──────────────────────────────────────────────────────

    #[Test]
    public function an_empty_post_is_refused_before_any_request(): void
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => []]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->tweets->create('   ');
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertStringContainsString('needs content', $e->getMessage());
            self::assertCount(0, $http->calls, 'no rate-limit budget may be spent on an empty draft');
        }
    }

    #[Test]
    public function the_trimmed_content_is_what_gets_sent(): void
    {
        // Validating a trimmed value and then transmitting the untrimmed one is
        // an easy, real bug: the local check passes and the server still
        // receives the whitespace.
        $client = $this->clientReturning($this->tweetRow(), $http);
        $client->tweets->create('  hello  ');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('hello', $body['content']);
    }

    // ─── Optional fields ─────────────────────────────────────────────────────

    #[Test]
    public function an_omitted_optional_field_is_absent_from_the_body_entirely(): void
    {
        // Sending "groupId": null is not the same as omitting it: a null tells
        // the server a group was named and could not be resolved, while absence
        // says the post belongs on the home feed. Absence must mean absence.
        $client = $this->clientReturning($this->tweetRow(), $http);
        $client->tweets->create('hello');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['content'], \array_keys($body));
    }

    #[Test]
    public function a_group_post_carries_its_group_id(): void
    {
        $client = $this->clientReturning($this->tweetRow(), $http);
        $client->tweets->create('hello', groupId: 'g-1');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('g-1', $body['groupId']);
    }

    #[Test]
    public function an_events_required_arabic_title_reaches_the_wire(): void
    {
        // titleArabic is required rather than optional because community-internal
        // pages are Arabic-only by design; an event without it renders blank.
        $client = $this->clientReturning($this->eventRow(), $http);
        $client->events->create('Launch', 'التدشين', '2026-09-01T10:00:00Z', '2026-09-01T12:00:00Z');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('التدشين', $body['titleArabic']);
        self::assertSame('2026-09-01T10:00:00Z', $body['startDate']);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────────

    private function rowFor(string $section): array
    {
        return match ($section) {
            'tweets' => $this->tweetRow(),
            'events' => $this->eventRow(),
            'blogs' => ['id' => 'b1', 'title' => 'T', 'createdAt' => '2026-01-01T00:00:00Z'],
            'albums' => ['id' => 'a1', 'title' => 'T', 'createdAt' => '2026-01-01T00:00:00Z'],
        };
    }

    private function tweetRow(): array
    {
        return ['id' => 't1', 'content' => 'x', 'createdAt' => '2026-01-01T00:00:00Z'];
    }

    private function eventRow(): array
    {
        return [
            'id' => 'e1',
            'title' => 'T',
            'startsAt' => '2026-09-01T10:00:00Z',
            'createdAt' => '2026-01-01T00:00:00Z',
        ];
    }
}
