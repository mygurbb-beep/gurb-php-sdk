<?php

declare(strict_types=1);

namespace Gurb;

/**
 * A built partner event, ready to store and send.
 *
 * Plain data on purpose: save `toArray()` (as JSON) in your outbox in the same
 * database transaction as the change it reports, and rebuild it with
 * `fromArray()` in the worker that sends it. `$body` is the exact string that is
 * signed and sent — never rebuild it from `$type`.
 */
final class PartnerEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $createdAt,
        public readonly string $body,
    ) {
    }

    /** @return array{id: string, type: string, createdAt: string, body: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'createdAt' => $this->createdAt, 'body' => $this->body];
    }

    /** @param array<string, mixed> $data As produced by toArray(). */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'type', 'createdAt', 'body'] as $key) {
            if (!\is_string($data[$key] ?? null)) {
                throw GurbApiException::localValidation("A stored partner event needs a string '{$key}'");
            }
        }

        return new self($data['id'], $data['type'], $data['createdAt'], $data['body']);
    }
}
