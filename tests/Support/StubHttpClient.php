<?php

declare(strict_types=1);

namespace Gurb\Tests\Support;

use Gurb\Http\HttpClient;
use Gurb\Http\HttpRequest;
use Gurb\Http\HttpResponse;
use Gurb\Http\TransportException;

/**
 * Records every call and replays a canned response.
 *
 * This is why HttpClient is an interface: no network, no mocking framework, no
 * global function to monkey-patch. The stub is a real transport, so the code
 * under test takes exactly the path it takes in production.
 */
final class StubHttpClient implements HttpClient
{
    /** @var list<HttpRequest> */
    public array $calls = [];

    private function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly ?TransportException $failure,
    ) {
    }

    public static function json(int $status, mixed $body): self
    {
        return new self($status, \json_encode($body, \JSON_THROW_ON_ERROR), null);
    }

    public static function raw(int $status, string $body): self
    {
        return new self($status, $body, null);
    }

    /** Simulates DNS failure, refused connection, TLS error or timeout. */
    public static function failing(string $message = 'connection refused', bool $timedOut = false): self
    {
        return new self(0, '', new TransportException($message, $timedOut));
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->calls[] = $request;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new HttpResponse($this->status, $this->body);
    }

    public function lastCall(): HttpRequest
    {
        if ($this->calls === []) {
            throw new \LogicException('No request was made.');
        }

        return $this->calls[\count($this->calls) - 1];
    }
}
