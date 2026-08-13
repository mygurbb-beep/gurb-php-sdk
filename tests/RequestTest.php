<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\GurbErrorCode;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private const KEY = ApiKeyTest::KEY;

    #[Test]
    public function it_sends_the_key_as_a_header_and_never_in_the_query_string(): void
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => ['id' => 'c1']]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        $client->community->get();

        self::assertCount(1, $http->calls);
        self::assertSame('https://x.test/api/sdk/community', $http->lastCall()->url);
        self::assertStringNotContainsString(self::KEY, $http->lastCall()->url);
        self::assertSame(self::KEY, $http->lastCall()->headers['X-Api-Key']);
    }

    #[Test]
    public function it_appends_api_itself_so_the_base_url_stays_an_origin(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);
        // Trailing slashes are a copy-paste artefact, not an error.
        $client = new GurbClient(self::KEY, 'https://x.test///', $http);

        $client->community->get();

        self::assertSame('https://x.test/api/sdk/community', $http->lastCall()->url);
    }

    #[Test]
    public function it_unwraps_the_success_data_envelope(): void
    {
        $http = StubHttpClient::json(200, [
            'success' => true,
            'data' => ['id' => 'c1', 'slug' => 'demo', 'type' => 'PUBLIC', 'memberCount' => 12],
        ]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        $community = $client->community->get();

        self::assertSame('c1', $community->id);
        self::assertSame('demo', $community->slug);
        self::assertTrue($community->isPublic(), 'isPublic is derived from type, never a stored field.');
    }

    #[Test]
    public function it_reads_a_bare_unwrapped_body_too(): void
    {
        $http = StubHttpClient::json(200, ['id' => 'c1', 'slug' => 'bare']);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        self::assertSame('bare', $client->community->get()->slug);
    }

    #[Test]
    public function it_drops_null_query_params_instead_of_sending_the_string_null(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['items' => []]]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        $client->events->list(limit: 10);

        self::assertSame('https://x.test/api/sdk/events?limit=10', $http->lastCall()->url);
    }

    #[Test]
    public function it_serialises_a_boolean_query_param_as_true_not_1(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['items' => []]]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        $client->events->list(page: 2, limit: 5, upcoming: true);

        // PHP would cast true to "1"; the API expects the JSON spelling.
        self::assertSame('https://x.test/api/sdk/events?page=2&limit=5&upcoming=true', $http->lastCall()->url);
    }

    #[Test]
    public function it_maps_a_recognised_backend_error_code_through_unchanged(): void
    {
        $http = StubHttpClient::json(403, [
            'success' => false,
            'error' => 'FEATURE_NOT_AVAILABLE',
            'message' => 'Blogs are not on your plan',
            'requestId' => 'req_123',
        ]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->blogs->list();
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::FEATURE_NOT_AVAILABLE, $e->code());
            self::assertSame(403, $e->status());
            self::assertSame('Blogs are not on your plan', $e->getMessage());
            self::assertSame('req_123', $e->requestId());
        }
    }

    #[Test]
    public function it_does_not_pass_an_unrecognised_backend_string_through_as_a_code(): void
    {
        $http = StubHttpClient::json(403, ['success' => false, 'error' => 'SOMETHING_NEW_WE_ADDED']);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->blogs->list();
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            // Consumers switch on `code`; coupling it to unversioned backend
            // copy would break them silently the day the backend adds a string.
            self::assertSame(GurbErrorCode::FORBIDDEN_SURFACE, $e->code());
            // The original text is not lost, only unpromoted.
            self::assertSame('SOMETHING_NEW_WE_ADDED', $e->getMessage());
        }
    }

    #[Test]
    public function it_derives_a_code_from_the_status_when_the_body_says_nothing(): void
    {
        foreach ([401 => GurbErrorCode::INVALID_API_KEY,
                  404 => GurbErrorCode::NOT_FOUND,
                  429 => GurbErrorCode::RATE_LIMITED,
                  422 => GurbErrorCode::VALIDATION_ERROR,
                  500 => GurbErrorCode::UNKNOWN] as $status => $expected) {
            $client = new GurbClient(self::KEY, 'https://x.test', StubHttpClient::json($status, []));

            try {
                $client->tweets->list();
                self::fail("Expected a GurbApiException for status {$status}.");
            } catch (GurbApiException $e) {
                self::assertSame($expected, $e->code(), "status {$status}");
                self::assertSame($status, $e->status());
            }
        }
    }

    #[Test]
    public function only_transient_failures_are_retryable(): void
    {
        self::assertTrue($this->isRetryable(429), 'rate limited: back off and retry');
        self::assertTrue($this->isRetryable(503), '5xx: the server may recover');
        self::assertFalse($this->isRetryable(403), 'a permission failure will not fix itself');
        self::assertFalse($this->isRetryable(422), 'a bad argument will not fix itself');
    }

    private function isRetryable(int $status): bool
    {
        try {
            (new GurbClient(self::KEY, 'https://x.test', StubHttpClient::json($status, [])))->tweets->list();
        } catch (GurbApiException $e) {
            return $e->isRetryable();
        }

        self::fail("Status {$status} should have thrown.");
    }

    #[Test]
    public function it_reports_a_transport_failure_as_network_error_not_a_leaked_exception(): void
    {
        $client = new GurbClient(self::KEY, 'https://x.test', StubHttpClient::failing());

        try {
            $client->tweets->list();
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::NETWORK_ERROR, $e->code());
            self::assertSame(0, $e->status());
            self::assertTrue($e->isRetryable());
        }
    }

    #[Test]
    public function a_timeout_says_so_in_the_message(): void
    {
        $client = new GurbClient(
            self::KEY,
            'https://x.test',
            StubHttpClient::failing('timed out', timedOut: true),
            timeoutMs: 2500,
        );

        try {
            $client->tweets->list();
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::NETWORK_ERROR, $e->code());
            self::assertStringContainsString('timed out after 2500ms', $e->getMessage());
        }
    }

    #[Test]
    public function a_non_json_body_does_not_end_up_in_the_exception_message(): void
    {
        // A WAF or proxy error page. Echoing it back could carry anything the
        // proxy decided to print, including request headers.
        $html = '<html><body>502 Bad Gateway — upstream token abc123</body></html>';
        $client = new GurbClient(self::KEY, 'https://x.test', StubHttpClient::raw(502, $html));

        try {
            $client->tweets->list();
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertStringNotContainsString('abc123', $e->getMessage());
            self::assertSame(502, $e->status());
        }
    }

    #[Test]
    public function a_truly_empty_body_is_an_empty_payload_not_a_parse_failure(): void
    {
        // A DELETE answering 204 with nothing at all. The TypeScript SDK reads
        // `text.length > 0 ? JSON.parse(text) : {}`, so "" is a payload of {}
        // and not an error. Nothing here may throw.
        $http = StubHttpClient::raw(204, '');
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        $client->members->remove('mem_1');

        self::assertSame('DELETE', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/members/mem_1', $http->lastCall()->url);
    }

    #[Test]
    public function a_whitespace_only_body_is_a_parse_failure_exactly_as_it_is_in_typescript(): void
    {
        // The drift this pins down: trimming before the emptiness check would
        // make PHP accept a blank page from a proxy and hand back a Community
        // with an empty id and a PRIVATE visibility it never asserted. The
        // TypeScript SDK raises here — JSON.parse("  ") throws — so PHP must.
        $client = new GurbClient(self::KEY, 'https://x.test', StubHttpClient::raw(200, "   \n"));

        try {
            $client->community->get();
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::UNKNOWN, $e->code(), 'a 2xx that will not parse is UNKNOWN');
            self::assertSame(200, $e->status());
            self::assertStringContainsString('non-JSON response', $e->getMessage());
        }
    }

    #[Test]
    public function a_missing_default_transport_is_a_gurb_exception_not_a_raw_runtime_error(): void
    {
        // CurlHttpClient signals a missing ext-curl with a TransportException,
        // and that type is documented as never escaping the SDK. It would
        // escape here — the client builds the transport before any Requester
        // exists to catch it — so a consumer following the README's "there is
        // exactly one thing to catch" would meet an uncaught RuntimeException.
        // The TypeScript SDK raises NETWORK_ERROR with status 0 when there is
        // no fetch; this is the same answer to the same question.
        if (\function_exists('curl_init')) {
            self::markTestSkipped(
                'ext-curl is present, so the default transport builds. Run the suite with '
                . 'disable_functions=curl_init to exercise this path.',
            );
        }

        try {
            new GurbClient(self::KEY, 'https://x.test');
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::NETWORK_ERROR, $e->code());
            self::assertSame(0, $e->status(), 'nothing was ever sent');
            self::assertStringContainsString('ext-curl', $e->getMessage(), 'the message names the fix');
        }
    }

    #[Test]
    public function it_maps_a_page_of_results_into_typed_objects(): void
    {
        $http = StubHttpClient::json(200, ['data' => [
            'items' => [[
                'id' => 't1',
                'content' => 'مرحبا',
                'author' => ['userId' => 'u1', 'displayName' => 'Sara', 'avatarUrl' => null],
                'imageUrls' => ['https://cdn.test/a.jpg', 42],
                'likeCount' => 3,
                'commentCount' => 1,
                'createdAt' => '2026-08-11T10:00:00Z',
                'editedAt' => null,
            ]],
            'page' => 1,
            'limit' => 20,
            'total' => 40,
            'hasMore' => true,
        ]]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        $page = $client->tweets->list(limit: 20);

        self::assertCount(1, $page);
        self::assertSame(40, $page->total);
        self::assertTrue($page->hasMore);
        $tweet = $page->items[0];
        self::assertSame('u1', $tweet->author->userId);
        self::assertNull($tweet->editedAt);
        // The stray 42 is dropped rather than coerced to "42": a caller
        // building an <img src> should never receive a non-URL.
        self::assertSame(['https://cdn.test/a.jpg'], $tweet->imageUrls);
    }
}
