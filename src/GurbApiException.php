<?php

declare(strict_types=1);

namespace Gurb;

use RuntimeException;

/**
 * The only exception this SDK throws.
 *
 * One class, not a hierarchy: a consumer writing `catch (GurbApiException $e)`
 * should need one catch block and then branch on `$e->code()`. Transport
 * failures are mapped into it too (NETWORK_ERROR) so "the request failed" and
 * "the server said no" are not two different shapes to handle.
 *
 * WHY METHODS AND NOT READONLY PROPERTIES — the TypeScript SDK exposes
 * `e.code` / `e.status` / `e.requestId` as fields, and this class cannot: PHP's
 * built-in Exception already declares a protected int `$code`, and a subclass
 * may not redeclare an inherited property as readonly (fatal error at compile
 * time). Getters are also the ordinary PHP convention for exceptions. The
 * native `getCode()` is kept useful by carrying the HTTP status, which is the
 * value generic log handlers expect to find there.
 */
class GurbApiException extends RuntimeException
{
    /**
     * @param string      $errorCode One of the GurbErrorCode constants.
     * @param int         $status    HTTP status, or 0 when no response happened
     *                               (bad key shape, DNS failure, timeout).
     * @param string|null $requestId Echo this to support; it identifies the call
     *                               in the platform's own logs.
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
        private readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** A stable GurbErrorCode. Branch on this, never on the message text. */
    public function code(): string
    {
        return $this->errorCode;
    }

    /** HTTP status, or 0 when the request never reached a server. */
    public function status(): int
    {
        return $this->status;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Retrying only ever helps for these. Anything else is a client-side bug,
     * and retrying it just spends your rate-limit budget on the same 403.
     */
    public function isRetryable(): bool
    {
        return $this->errorCode === GurbErrorCode::NETWORK_ERROR
            || $this->errorCode === GurbErrorCode::RATE_LIMITED
            || $this->status >= 500;
    }

    /**
     * Map an HTTP failure onto a stable code.
     *
     * The backend's `error` field wins when we recognise it. Unrecognised
     * strings are NOT passed through as the code — a consumer switching on it
     * would then be coupled to backend copy that can change without a version
     * bump. Unknown falls back to the status-derived code and the original text
     * survives in the message, so nothing is lost, only unpromoted.
     *
     * @param mixed $body The decoded response body, whatever shape it arrived in.
     */
    public static function fromResponse(int $status, mixed $body): self
    {
        $parsed = \is_array($body) ? $body : [];

        $declared = \is_string($parsed['error'] ?? null) ? $parsed['error'] : null;
        $message = \is_string($parsed['message'] ?? null) ? $parsed['message'] : null;
        $requestId = \is_string($parsed['requestId'] ?? null) ? $parsed['requestId'] : null;

        $code = GurbErrorCode::UNKNOWN;
        if ($declared !== null && \in_array($declared, GurbErrorCode::DECLARABLE_BY_BACKEND, true)) {
            $code = $declared;
        } elseif ($status === 401) {
            $code = GurbErrorCode::INVALID_API_KEY;
        } elseif ($status === 403) {
            $code = GurbErrorCode::FORBIDDEN_SURFACE;
        } elseif ($status === 404) {
            $code = GurbErrorCode::NOT_FOUND;
        } elseif ($status === 429) {
            $code = GurbErrorCode::RATE_LIMITED;
        } elseif ($status === 400 || $status === 422) {
            $code = GurbErrorCode::VALIDATION_ERROR;
        }

        return new self(
            $message ?? $declared ?? "Gurb API request failed with status {$status}",
            $code,
            $status,
            $requestId,
        );
    }
}
