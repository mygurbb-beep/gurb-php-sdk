<?php

declare(strict_types=1);

namespace Gurb\Http;

use RuntimeException;

/**
 * "The request never reached a server, or never came back."
 *
 * This never escapes the SDK: Internal\Requester catches it and rethrows a
 * GurbApiException with code NETWORK_ERROR, so a consumer has exactly one
 * exception type to catch. It exists as its own class only so a transport can
 * signal "no response" distinctly from "a response I did not like".
 */
final class TransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $timedOut = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
