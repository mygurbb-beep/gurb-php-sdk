<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\Embed\EmbedSnippet;
use Gurb\EmbedSection;
use Gurb\GurbClient;
use Gurb\Tests\Support\StubHttpClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmbedTest extends TestCase
{
    private const KEY = ApiKeyTest::KEY;

    private function session(): StubHttpClient
    {
        return StubHttpClient::json(200, ['data' => [
            'token' => 'et_abc',
            'expiresAt' => '2026-08-11T00:02:00Z',
            'userId' => 'u1',
        ]]);
    }

    #[Test]
    public function it_posts_the_identity_payload_and_carries_no_role_field(): void
    {
        $http = $this->session();
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        $session = $client->createEmbedSession('shop-user-42', 'Sara');

        $call = $http->lastCall();
        self::assertSame('https://x.test/api/embed/sessions', $call->url);
        self::assertSame('POST', $call->method);
        self::assertSame('application/json', $call->headers['Content-Type']);

        $body = \json_decode((string) $call->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['externalUserId' => 'shop-user-42', 'displayName' => 'Sara'], $body);

        // The security claim, asserted rather than assumed: a host cannot elevate
        // a user, because there is no field on the wire that could carry a role.
        self::assertArrayNotHasKey('role', $body);

        self::assertSame('et_abc', $session->token);
        self::assertSame('u1', $session->userId);
    }

    #[Test]
    public function optional_identity_fields_are_omitted_not_sent_as_null(): void
    {
        $http = $this->session();
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        $client->createEmbedSession('shop-user-42', 'Sara', avatarUrl: 'https://cdn.test/a.png');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([
            'externalUserId' => 'shop-user-42',
            'displayName' => 'Sara',
            'avatarUrl' => 'https://cdn.test/a.png',
        ], $body);
        self::assertArrayNotHasKey('email', $body);
    }

    /**
     * THESE ASSERTIONS ARE THE CONTRACT with `app/embed/page.tsx` in the Gurb web
     * app, and with the identical boundary tests in `@gurb/embed` and
     * `@gurb/server`. Changing one requires changing the others in the same
     * commit.
     *
     * They replaced assertions pinning `/embed/{slug}/{section}#t=`, which were
     * green and wrong: the page lives at `/embed` and reads `token`, so this
     * method named a route that does not exist and a fragment key nothing reads.
     * The tests asserted the string this method produced rather than the shape
     * the page consumes, which is the only kind of assertion that can be both
     * passing and useless.
     */
    #[Test]
    public function it_points_at_embed_with_no_slug_and_no_section_segment(): void
    {
        $client = new GurbClient(self::KEY, 'https://x.test', StubHttpClient::json(200, []));

        $url = $client->buildEmbedUrl(null, EmbedSection::Community, 'et_abc');

        self::assertSame('https://x.test/embed', \explode('#', $url)[0]);
    }

    #[Test]
    public function it_names_the_token_token_which_is_the_key_the_page_reads(): void
    {
        $client = new GurbClient(self::KEY, 'https://x.test', StubHttpClient::json(200, []));

        \parse_str(
            (string) \parse_url($client->buildEmbedUrl(null, EmbedSection::Community, 'et_abc'), \PHP_URL_FRAGMENT),
            $fragment,
        );

        self::assertSame('et_abc', $fragment['token']);
        // Absence means "the community home", which is where the page sends an
        // arriving member anyway.
        self::assertArrayNotHasKey('section', $fragment);
    }

    #[Test]
    public function it_carries_the_section_for_a_single_pane(): void
    {
        $client = new GurbClient(self::KEY, 'https://x.test', StubHttpClient::json(200, []));

        \parse_str(
            (string) \parse_url($client->buildEmbedUrl(null, EmbedSection::Tweets, 'et_abc'), \PHP_URL_FRAGMENT),
            $fragment,
        );

        self::assertSame('tweets', $fragment['section']);
    }

    #[Test]
    public function it_puts_the_token_in_the_fragment_never_the_query_string(): void
    {
        $client = new GurbClient(self::KEY, 'https://x.test', StubHttpClient::json(200, []));

        $url = $client->buildEmbedUrl(null, EmbedSection::Tweets, 'et_abc');

        // A token in a query string would be written to nginx access logs and
        // Referer headers. A fragment is never transmitted to a server at all.
        self::assertNull(\parse_url($url, \PHP_URL_QUERY));
        self::assertStringContainsString('token=et_abc', (string) \parse_url($url, \PHP_URL_FRAGMENT));
    }

    #[Test]
    public function it_escapes_a_token_that_could_break_out_of_the_url(): void
    {
        $client = new GurbClient(self::KEY, 'https://x.test', StubHttpClient::json(200, []));

        $url = $client->buildEmbedUrl(null, EmbedSection::Albums, 'tok en&x=1');

        self::assertNull(\parse_url($url, \PHP_URL_QUERY));
        \parse_str((string) \parse_url($url, \PHP_URL_FRAGMENT), $fragment);
        // Round-trips: whatever escaping was applied, the page reads back the
        // token that was actually minted.
        self::assertSame('tok en&x=1', $fragment['token']);
        self::assertSame('albums', $fragment['section']);
    }

    #[Test]
    public function the_snippet_mounts_with_the_token_and_the_clients_base_url(): void
    {
        $client = new GurbClient(self::KEY, 'https://x.test', StubHttpClient::json(200, []));

        $html = $client->embedSnippet()->render('community', 'demo', EmbedSection::Tweets, 'et_abc');

        self::assertStringContainsString('<div id="community"></div>', $html);
        self::assertStringContainsString('GurbEmbed.mount(', $html);
        self::assertStringContainsString('"token":"et_abc"', $html);
        self::assertStringContainsString('"section":"tweets"', $html);
        self::assertStringContainsString('"baseUrl":"https://x.test"', $html);
    }

    #[Test]
    public function the_snippet_refuses_to_print_a_secret_api_key_into_a_page(): void
    {
        $snippet = new EmbedSnippet();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('secret API key, not an embed token');

        // The mistake this catches: both strings are "the Gurb credential" to
        // someone wiring this up quickly, and only one of them is safe to print.
        $snippet->render('community', 'demo', EmbedSection::Tweets, self::KEY);
    }

    #[Test]
    public function the_snippet_cannot_be_escaped_with_a_closing_script_tag(): void
    {
        $snippet = new EmbedSnippet();

        $html = $snippet->render('community', '</script><script>alert(1)</script>', EmbedSection::Tweets, 'et_abc');

        // JSON_HEX_TAG rewrites `<` and `>` as < and >, so the
        // injected markup stays a JS string instead of closing the block early
        // and handing the visitor's browser attacker-authored script.
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('\\u003Cscript', $html);
    }

    #[Test]
    public function the_async_snippet_keeps_the_token_out_of_the_markup_entirely(): void
    {
        $html = (new EmbedSnippet('https://x.test'))
            ->renderAsync('community', 'demo', EmbedSection::Events, '/api/gurb-token');

        self::assertStringContainsString('fetchToken', $html);
        self::assertStringContainsString('"/api/gurb-token"', $html);
        // Nothing to leak into a cached page: the markup carries an endpoint,
        // not a credential.
        self::assertStringNotContainsString('"token"', $html);
    }

    // ─── The script-free iframe ──────────────────────────────────────────────

    #[Test]
    public function the_iframe_helper_emits_NO_external_script(): void
    {
        // The whole point. render()/renderAsync() pull a loader from unpkg, and
        // that loader's only feature over a plain frame is auto-resize driven by
        // a postMessage the Gurb embed page does not send. So the CDN dependency
        // buys nothing today, and this helper exists to avoid it entirely.
        $html = (new EmbedSnippet('https://gurb.test'))
            ->iframe(EmbedSection::Community, 'gurbe_' . \str_repeat('x', 43));

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('unpkg', $html);
        self::assertStringStartsWith('<iframe', $html);
    }

    #[Test]
    public function the_token_rides_in_the_fragment_never_the_query_string(): void
    {
        // A query string reaches the server: Gurb's nginx log, your own proxy's
        // log, and the Referer of every outbound link the framed page renders.
        // The token is a live credential, so that is the difference between a
        // short-lived secret and a logged one.
        $token = 'gurbe_' . \str_repeat('x', 43);
        $html = (new EmbedSnippet('https://gurb.test'))
            ->iframe(EmbedSection::Community, $token);

        self::assertStringContainsString('/embed#token=' . $token, $html);
        self::assertStringNotContainsString('?token=', $html);
    }

    #[Test]
    public function the_whole_community_omits_the_section_key(): void
    {
        // Absence means "the community home". This mirrors @gurb/embed's own
        // boundary tests exactly — the two sides describe ONE contract, and
        // changing either requires changing the other in the same commit.
        $html = (new EmbedSnippet('https://gurb.test'))
            ->iframe(EmbedSection::Community, 'gurbe_' . \str_repeat('x', 43));

        self::assertStringNotContainsString('section=', $html);
    }

    #[Test]
    public function a_single_pane_carries_its_section(): void
    {
        $html = (new EmbedSnippet('https://gurb.test'))
            ->iframe(EmbedSection::Tweets, 'gurbe_' . \str_repeat('x', 43));

        self::assertStringContainsString('section=tweets', $html);
    }

    #[Test]
    public function the_whole_community_gets_more_room_than_a_pane(): void
    {
        $token = 'gurbe_' . \str_repeat('x', 43);
        $snippet = new EmbedSnippet('https://gurb.test');

        // At 600px a whole community shows a navigation bar and little else in
        // the moment before anything resizes — and that moment is when someone
        // decides the integration is broken.
        self::assertStringContainsString('height:900px', $snippet->iframe(EmbedSection::Community, $token));
        self::assertStringContainsString('height:600px', $snippet->iframe(EmbedSection::Tweets, $token));
    }

    #[Test]
    public function the_sandbox_keeps_same_origin_and_refuses_top_navigation(): void
    {
        $html = (new EmbedSnippet('https://gurb.test'))
            ->iframe(EmbedSection::Community, 'gurbe_' . \str_repeat('x', 43));

        // WITHOUT allow-same-origin the embed cannot work at all: the page keeps
        // its session in sessionStorage and rewrites its own URL, and an opaque
        // origin makes both throw.
        self::assertStringContainsString('allow-same-origin', $html);
        self::assertStringContainsString('allow-scripts', $html);
        // Nothing inside the frame may navigate the HOST page away.
        self::assertStringNotContainsString('allow-top-navigation', $html);
    }
}
