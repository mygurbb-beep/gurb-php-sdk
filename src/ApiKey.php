<?php

declare(strict_types=1);

namespace Gurb;

/**
 * Shape checks for a Gurb API key.
 *
 * A key is `gurb_<keyId>_<secret>`. The `gurb_` prefix exists so a key is
 * distinguishable from a session JWT (which starts `eyJ`) by shape alone — the
 * backend routes on that, and so do secret scanners like gitleaks.
 *
 * Validating at construction rather than on first request means an integrator
 * who pasted the wrong string learns immediately, instead of debugging a 401
 * against a route that was never the problem.
 */
final class ApiKey
{
    /**
     * Mirrors the TypeScript SDK's regex character for character, so the two
     * SDKs cannot disagree about what a valid key is.
     *
     * Real secrets are 43 chars (32 random bytes, base64url, unpadded). The
     * bound is 20 rather than 43 because this check's job is catching pasted
     * JWTs and truncated copy-pastes, not authenticating — only the server can
     * do that, and tightening it here would reject any future key format
     * without a server change.
     */
    private const PATTERN = '/^gurb_[a-f0-9]{12}_[A-Za-z0-9_-]{20,}$/';

    private function __construct()
    {
    }

    /**
     * @throws GurbApiException with status 0 — nothing was ever sent.
     */
    public static function assertValid(string $key): void
    {
        if ($key === '') {
            throw new GurbApiException(
                'A Gurb API key is required. Pass one to new GurbClient($apiKey).',
                GurbErrorCode::INVALID_API_KEY,
                0,
            );
        }

        if (\preg_match(self::PATTERN, $key) !== 1) {
            $looksLikeJwt = \str_starts_with($key, 'eyJ');

            throw new GurbApiException(
                $looksLikeJwt
                    ? 'That is a session JWT, not an API key. API keys look like gurb_<id>_<secret> and are minted from the community dashboard.'
                    : 'Malformed Gurb API key. Expected the format gurb_<id>_<secret>.',
                GurbErrorCode::INVALID_API_KEY,
                0,
            );
        }
    }

    /**
     * True for anything shaped like a secret key.
     *
     * Used by the embed snippet renderer to refuse to print a key into HTML.
     * It is deliberately looser than assertValid(): when the question is "might
     * this be a secret?", a truncated or future-format key must still answer yes.
     */
    public static function looksLikeSecret(string $value): bool
    {
        return \str_starts_with($value, 'gurb_');
    }
}
