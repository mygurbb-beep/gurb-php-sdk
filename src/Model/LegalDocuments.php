<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * A community's OWN terms of use and privacy policy.
 *
 * Not the platform's. Gurb has its own legal pages, shared by every tenant and
 * editable only by its staff; these two texts belong to one community and are
 * what its members agree to when they join.
 *
 * They are readable WITHOUT ANY PERMISSION, for every community type including
 * private ones, and that is deliberate upstream: a prospective member has to be
 * able to read the rules before agreeing to them.
 */
final class LegalDocuments
{
    private function __construct(
        public readonly string $terms,
        public readonly string $privacyPolicy,
        /**
         * True when `$terms` is the platform's standard template rather than
         * text this community wrote.
         *
         * ALWAYS FALSE ON THE RESPONSE TO A WRITE — that payload does not carry
         * the flag, and the decoder will not invent one. Re-read with `get()`
         * if you need it after an update.
         */
        public readonly bool $isDefaultTerms,
        /** Same caveat as `$isDefaultTerms`. */
        public readonly bool $isDefaultPrivacyPolicy,
        public readonly string $termsVersion,
        public readonly string $privacyVersion,
        /** `ar` or `en` — which language's standard template a blank field resolves to. */
        public readonly string $language,
        public readonly ?string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            terms: Decode::str($data, 'terms'),
            privacyPolicy: Decode::str($data, 'privacyPolicy'),
            isDefaultTerms: Decode::bool($data, 'isDefaultTerms'),
            isDefaultPrivacyPolicy: Decode::bool($data, 'isDefaultPrivacyPolicy'),
            termsVersion: Decode::str($data, 'termsVersion'),
            privacyVersion: Decode::str($data, 'privacyVersion'),
            language: Decode::str($data, 'language', 'ar'),
            updatedAt: Decode::nullableStr($data, 'updatedAt'),
        );
    }
}
