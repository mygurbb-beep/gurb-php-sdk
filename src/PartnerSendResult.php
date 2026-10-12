<?php

declare(strict_types=1);

namespace Gurb;

/**
 * What happened to one send, and therefore what your outbox does next.
 *
 *   delivered  2xx — applied, or safely ignored (duplicate, stale, unknown member). Mark it delivered.
 *   rejected   400/401/403/404 — retrying cannot help: fix the event or the setup, alert a human.
 *   retry      408/429/5xx, timeout or network error — keep it and retry with back-off.
 */
final class PartnerSendResult
{
    public const DELIVERED = 'delivered';
    public const REJECTED = 'rejected';
    public const RETRY = 'retry';

    public function __construct(
        /** One of the constants above. */
        public readonly string $outcome,
        /** HTTP status, or 0 when no response arrived. */
        public readonly int $status,
        /** Gurb's stable code on a refusal (e.g. HOST_EVENT_INVALID), when it sent one. */
        public readonly ?string $code = null,
        /** What Gurb did with a delivered event: applied | duplicate | stale | unknown_member | ignored. */
        public readonly ?string $applied = null,
        /** Gurb's message, or the transport error. Never logged by the SDK. */
        public readonly ?string $message = null,
    ) {
    }

    public function isDelivered(): bool
    {
        return $this->outcome === self::DELIVERED;
    }

    public function shouldRetry(): bool
    {
        return $this->outcome === self::RETRY;
    }
}
