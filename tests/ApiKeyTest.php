<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\GurbErrorCode;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    /** Shaped like a real key: 12 hex id, 43-char base64url secret. */
    public const KEY = 'gurb_a1b2c3d4e5f6_' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    #[Test]
    public function it_rejects_a_malformed_key_at_construction_not_on_first_request(): void
    {
        $this->expectException(GurbApiException::class);
        $this->expectExceptionMessage('Malformed Gurb API key');

        // No transport is passed: if validation were deferred to the first
        // request, this line would succeed and the test would fail here.
        new GurbClient('not-a-key');
    }

    #[Test]
    public function it_tells_a_developer_who_pasted_a_session_jwt_what_they_actually_did(): void
    {
        $this->expectException(GurbApiException::class);
        $this->expectExceptionMessage('session JWT, not an API key');

        new GurbClient('eyJhbGciOiJIUzI1NiJ9.abc.def');
    }

    #[Test]
    public function an_empty_key_names_the_missing_argument(): void
    {
        $this->expectException(GurbApiException::class);
        $this->expectExceptionMessage('A Gurb API key is required');

        new GurbClient('');
    }

    #[Test]
    public function key_errors_carry_status_zero_because_nothing_was_sent(): void
    {
        try {
            new GurbClient('not-a-key');
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::INVALID_API_KEY, $e->code());
            self::assertSame(0, $e->status());
            self::assertFalse($e->isRetryable(), 'Retrying a bad key never helps.');
        }
    }

    #[Test]
    public function it_accepts_a_well_formed_key(): void
    {
        $client = new GurbClient(self::KEY, httpClient: StubHttpClient::json(200, []));

        self::assertInstanceOf(GurbClient::class, $client);
    }
}
