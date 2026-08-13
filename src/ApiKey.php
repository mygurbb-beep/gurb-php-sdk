<?php

declare(strict_types=1);

namespace Gurb;

/**
 * Shape checks for the two Gurb credentials.
 *
 *   gurb_<12 hex>_<secret>       a community key   — one community, forever
 *   gurb_sa_<12 hex>_<secret>    a super-admin key — the whole platform
 *
 * The shapes are mutually exclusive BY CONSTRUCTION, not by check order: `sa` is
 * not twelve hex characters, so an admin key can never satisfy the community
 * pattern and vice versa. That matters more than it looks. The blast radius of
 * the two is not comparable — a leaked community key creates junk in one
 * community, a leaked admin key can create communities and mint further keys —
 * so the wrong one must fail loudly at construction rather than quietly at the
 * first 403 in production.
 *
 * The `gurb_` prefix also distinguishes both from a session JWT (which starts
 * `eyJ`), which is what the platform routes on and what secret scanners like
 * gitleaks match.
 *
 * Validating at construction rather than on first request means an integrator
 * who pasted the wrong string learns immediately, instead of debugging a 401
 * against a route that was never the problem.
 */
final class ApiKey
{
    /**
     * Mirrors the TypeScript SDK's regexes character for character, so the two
     * SDKs cannot disagree about what a valid key is.
     *
     * Real secrets are 43 chars (32 random bytes, base64url, unpadded). The
     * bound is 20 rather than 43 because this check's job is catching pasted
     * JWTs and truncated copy-pastes, not authenticating — only the server can
     * do that, and tightening it here would reject any future key format
     * without a server change.
     */
    private const COMMUNITY_PATTERN = '/^gurb_[a-f0-9]{12}_[A-Za-z0-9_-]{20,}$/';
    private const ADMIN_PATTERN = '/^gurb_sa_[a-f0-9]{12}_[A-Za-z0-9_-]{20,}$/';

    private function __construct()
    {
    }

    /**
     * Validate a community-scoped key: the credential `GurbClient` accepts.
     *
     * @throws GurbApiException with status 0 — nothing was ever sent.
     */
    public static function assertValid(string $key): void
    {
        self::assertPresent($key, 'API key', 'GurbClient($apiKey)');

        if (\preg_match(self::COMMUNITY_PATTERN, $key) === 1) {
            return;
        }

        if (\preg_match(self::ADMIN_PATTERN, $key) === 1) {
            // The most important message in this file. Handing an admin key to
            // ordinary application code is not a smaller version of the same
            // mistake as a typo — it is a privilege escalation, so say so
            // instead of "malformed key".
            self::reject(
                'That is a super-admin key, not a community API key. Use GurbAdminClient for platform operations — passing an admin key here would give ordinary application code far more authority than it needs.',
            );
        }

        self::reject(
            \str_starts_with($key, 'eyJ')
                ? 'That is a session JWT, not an API key. API keys look like gurb_<id>_<secret> and are minted from the community dashboard.'
                : 'Malformed Gurb API key. Expected the format gurb_<id>_<secret>.',
        );
    }

    /**
     * Validate a platform super-admin key: the credential `GurbAdminClient`
     * accepts.
     *
     * @throws GurbApiException with status 0 — nothing was ever sent.
     */
    public static function assertValidAdmin(string $key): void
    {
        self::assertPresent($key, 'super-admin key', 'GurbAdminClient($adminKey)');

        if (\preg_match(self::ADMIN_PATTERN, $key) === 1) {
            return;
        }

        if (\preg_match(self::COMMUNITY_PATTERN, $key) === 1) {
            // Harmless in security terms — this direction gives you *less*
            // authority, not more — but it would fail as a 403 on the first
            // admin call, and a 403 reads as "my key is broken" rather than
            // "I built the wrong client". Name the actual mistake.
            self::reject(
                'That is a community API key, not a super-admin key. Community keys cannot create communities or mint other keys; use GurbClient with it instead.',
            );
        }

        self::reject(
            \str_starts_with($key, 'eyJ')
                ? 'That is a session JWT, not a super-admin key. Admin keys look like gurb_sa_<id>_<secret>.'
                : 'Malformed Gurb super-admin key. Expected the format gurb_sa_<id>_<secret>.',
        );
    }

    /**
     * True for anything shaped like a secret key, of either kind.
     *
     * Used by the embed snippet renderer to refuse to print a key into HTML.
     * It is deliberately looser than the assertions above: when the question is
     * "might this be a secret?", a truncated key, an admin key or a future
     * format must all still answer yes.
     */
    public static function looksLikeSecret(string $value): bool
    {
        return \str_starts_with($value, 'gurb_');
    }

    /** @throws GurbApiException */
    private static function assertPresent(string $key, string $what, string $constructorHint): void
    {
        if ($key === '') {
            self::reject("A Gurb {$what} is required. Pass one to new {$constructorHint}.");
        }
    }

    /**
     * `never` is a real return type, not a comment: it tells PHP and any static
     * analyser that control does not come back, so the callers above do not need
     * an `else` and cannot accidentally fall through to a second message.
     *
     * @throws GurbApiException
     */
    private static function reject(string $message): never
    {
        // INVALID_API_KEY rather than VALIDATION_ERROR: the credential itself is
        // what is wrong, and a consumer catching this should rotate a key, not
        // fix an argument. Status 0 because nothing was ever sent.
        throw new GurbApiException($message, GurbErrorCode::INVALID_API_KEY, 0);
    }
}
