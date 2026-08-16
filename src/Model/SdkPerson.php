<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * A person, as the platform surface publishes one.
 *
 * `phone` is never null. When the account has no number it carries a run of
 * zeros and `phoneIsPlaceholder` is true.
 *
 * That is deliberate. An integration rendering a profile card wants a field of
 * the right SHAPE to lay out, and a null there becomes the literal string
 * "undefined" in somebody's template. The zeros are chosen so that nobody could
 * mistake them for a real number and try to dial them, and the flag means no
 * caller has to detect the placeholder by comparing strings — which is the kind
 * of check that silently stops working the day the placeholder changes.
 */
final class SdkPerson
{
    private function __construct(
        public readonly string $userId,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $phone,
        public readonly bool $phoneIsPlaceholder,
    ) {
    }

    /** The real number, or null when the platform only had a placeholder. */
    public function realPhone(): ?string
    {
        return $this->phoneIsPlaceholder ? null : $this->phone;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: Decode::str($data, 'userId'),
            email: Decode::str($data, 'email'),
            displayName: Decode::str($data, 'displayName', ''),
            phone: Decode::str($data, 'phone', ''),
            // Defaults TRUE when absent: assuming a number is real when the
            // server never said so is the direction that ends with somebody
            // dialling zeros.
            phoneIsPlaceholder: (bool) ($data['phoneIsPlaceholder'] ?? true),
        );
    }
}
