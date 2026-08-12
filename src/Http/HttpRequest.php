<?php

declare(strict_types=1);

namespace Gurb\Http;

/**
 * One outbound HTTP call, fully resolved.
 *
 * Deliberately dumb: no auth logic, no URL building, no retries. Everything a
 * transport needs is already in these four properties, which is what makes an
 * injected test double a three-line class instead of a mocking framework.
 */
final class HttpRequest
{
    /**
     * @param 'GET'|'POST'           $method
     * @param array<string, string>  $headers
     * @param string|null            $body      Already-encoded body, or null for GET.
     * @param int                    $timeoutMs Whole-request budget, not per-byte.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly int $timeoutMs,
    ) {
    }
}
