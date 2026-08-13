<?php

declare(strict_types=1);

namespace Gurb\Internal;

use Gurb\GurbApiException;
use Gurb\GurbErrorCode;
use Gurb\Http\HttpClient;
use Gurb\Http\HttpRequest;
use Gurb\Http\TransportException;

/**
 * Auth injection, URL building, error mapping and envelope unwrapping — once.
 *
 * @internal Not part of the public API. May change in any release.
 *
 * Every resource class holds one of these instead of a raw HttpClient, so there
 * is exactly one place that knows the API key exists and exactly one place that
 * decides what an error looks like. A resource cannot accidentally send the key
 * somewhere else, because it never sees it.
 *
 * BOTH CLIENTS SHARE THIS CLASS. `GurbClient` builds one with a community key
 * and `GurbAdminClient` builds one with a super-admin key, and neither has any
 * HTTP code of its own. That is deliberate: "how is a request authenticated,
 * how is an error shaped, how is a timeout reported" must have one answer, not
 * one per client. A drift between the community and admin surfaces on any of
 * those three would be a security difference, not a style difference — imagine
 * the admin path forgetting to keep the key out of the query string.
 */
final class Requester
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly HttpClient $httpClient,
        private readonly int $timeoutMs,
    ) {
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * PATCH and DELETE joined GET and POST when the membership write surface
     * landed. Nothing else changed: the same header, the same error mapping,
     * the same unwrapping. A verb is not a reason to fork this method.
     *
     * @param 'GET'|'POST'|'PATCH'|'DELETE'  $method
     * @param array<string, scalar|null>     $query Null values are dropped.
     * @param array<string, mixed>|null      $body
     *
     * @return array<string, mixed> The unwrapped payload. An empty array when
     *                              the endpoint answers `data: null` — a DELETE
     *                              has nothing to return, and callers of those
     *                              routes are typed `void` accordingly.
     *
     * @throws GurbApiException
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . '/api/' . \ltrim($path, '/') . $this->queryString($query);

        $headers = [
            // The key travels as a header and never as a query parameter:
            // query strings land in nginx access logs, browser history and
            // Referer headers, and a logged key is a leaked key.
            'X-Api-Key' => $this->apiKey,
            'Accept' => 'application/json',
        ];

        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = \json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            $headers['Content-Type'] = 'application/json';
        }

        try {
            $response = $this->httpClient->send(
                new HttpRequest($method, $url, $headers, $encodedBody, $this->timeoutMs),
            );
        } catch (TransportException $e) {
            throw new GurbApiException(
                $e->timedOut
                    ? "Request to {$path} timed out after {$this->timeoutMs}ms"
                    : "Network request to {$path} failed",
                GurbErrorCode::NETWORK_ERROR,
                0,
                previous: $e,
            );
        } catch (GurbApiException $e) {
            // An injected transport may already speak our language. Do not
            // re-wrap it and lose its code.
            throw $e;
        } catch (\Throwable $e) {
            // Any other transport (a Guzzle adapter, a hand-rolled stub) can
            // throw whatever it likes. A consumer still gets one exception type.
            throw new GurbApiException(
                "Network request to {$path} failed",
                GurbErrorCode::NETWORK_ERROR,
                0,
                previous: $e,
            );
        }

        $parsed = $this->decode($response->body, $response->status);

        if ($response->status < 200 || $response->status >= 300) {
            throw GurbApiException::fromResponse($response->status, $parsed);
        }

        // Responses are `{ success, data }` or bare. Unwrap when wrapped.
        $data = \array_key_exists('data', $parsed) ? $parsed['data'] : $parsed;

        return \is_array($data) ? $data : [];
    }

    /** @return array<string, mixed> */
    private function decode(string $body, int $status): array
    {
        if (\trim($body) === '') {
            return [];
        }

        try {
            $parsed = \json_decode($body, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // An HTML error page from a proxy or a WAF is the usual cause.
            // Surfacing the parse failure rather than the raw body keeps
            // whatever that body contained out of the host's logs.
            throw new GurbApiException(
                "Gurb API returned a non-JSON response (status {$status})",
                $status >= 200 && $status < 300 ? GurbErrorCode::UNKNOWN : GurbErrorCode::NETWORK_ERROR,
                $status,
            );
        }

        return \is_array($parsed) ? $parsed : [];
    }

    /**
     * Build `?a=1&b=2`, dropping nulls.
     *
     * Null is PHP's stand-in for the TypeScript SDK's `undefined`: an omitted
     * pagination argument must not travel as the literal string "null", which
     * the backend would then try to parse as a page number.
     *
     * @param array<string, scalar|null> $query
     */
    private function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            $encoded = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $pairs[] = \rawurlencode($key) . '=' . \rawurlencode($encoded);
        }

        return $pairs === [] ? '' : '?' . \implode('&', $pairs);
    }
}
