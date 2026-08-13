<?php

declare(strict_types=1);

namespace Contract;

use Gurb\Http\CurlHttpClient;
use Gurb\Http\HttpClient;
use Gurb\Http\HttpRequest;
use Gurb\Http\HttpResponse;

/**
 * Real HTTP, fully recorded.
 *
 * Wraps the SDK's own CurlHttpClient so the bytes on the wire are exactly the
 * bytes production sends, while still letting the harness assert on verb, URL,
 * headers, body and the status that came back.
 */
final class Recording implements HttpClient
{
    /** @var list<array{req: HttpRequest, status: int, body: string}> */
    public array $calls = [];

    public function __construct(private readonly HttpClient $inner = new CurlHttpClient())
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $response = $this->inner->send($request);
        $this->calls[] = ['req' => $request, 'status' => $response->status, 'body' => $response->body];

        return $response;
    }

    /** @return array{req: HttpRequest, status: int, body: string} */
    public function last(): array
    {
        if ($this->calls === []) {
            throw new \LogicException('No request was recorded.');
        }

        return $this->calls[\count($this->calls) - 1];
    }

    public function reset(): void
    {
        $this->calls = [];
    }
}

/** Minimal assertion harness — no PHPUnit here, this runs against a live server. */
final class Checks
{
    public int $passed = 0;

    /** @var list<string> */
    public array $failures = [];

    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
    }

    public function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected === $actual) {
            ++$this->passed;

            return;
        }

        $this->failures[] = \sprintf(
            "[%s] %s\n     expected: %s\n     actual:   %s",
            $this->group,
            $what,
            \json_encode($expected, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            \json_encode($actual, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        );
    }

    public function true(bool $condition, string $what): void
    {
        $this->same(true, $condition, $what);
    }

    public function report(): int
    {
        $total = $this->passed + \count($this->failures);
        echo "\n";
        foreach ($this->failures as $failure) {
            echo "FAIL {$failure}\n";
        }
        echo \sprintf(
            "%s  %d/%d checks passed, %d failed\n",
            $this->failures === [] ? 'CONTRACT OK' : 'CONTRACT FAILED',
            $this->passed,
            $total,
            \count($this->failures),
        );

        return $this->failures === [] ? 0 : 1;
    }
}
