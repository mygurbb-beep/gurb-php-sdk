<?php

declare(strict_types=1);

namespace Gurb\Http;

/**
 * A raw HTTP response.
 *
 * The body stays a string: decoding, envelope unwrapping and error mapping all
 * happen in one place (Internal\Requester) so a custom transport cannot change
 * how errors are shaped.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }
}
