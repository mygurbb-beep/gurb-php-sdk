<?php

declare(strict_types=1);

namespace Gurb\Resource\Admin;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Input\ExternalOwner;
use Gurb\Model\Community;
use Gurb\Model\Paginated;

/**
 * Every community on the platform. Super-admin only.
 *
 * Note the contrast with `Gurb\Resource\CommunityResource`, which has no
 * arguments at all because a community key IS a community. Here you can list
 * across tenants, which is precisely the authority the separate credential
 * exists to fence off.
 */
final class AdminCommunitiesResource
{
    /**
     * The slug rules, in one place so the messages and the checks cannot drift.
     *
     * These mirror the server's own rules. THAT IS A LIABILITY, not just a
     * convenience: a client-side copy of a server rule is wrong the moment the
     * server relaxes it, and then this SDK refuses slugs the platform would
     * happily accept, with no server-side change able to fix it. It is worth it
     * here only because the rule is a URL grammar rather than a policy — it
     * changes about as often as URLs do — and because the alternative is a
     * round-trip to learn you typed a capital letter.
     *
     * If the platform ever widens this (underscores, unicode slugs, shorter
     * names), widen it here in the same release.
     */
    public const SLUG_MIN_LENGTH = 3;
    public const SLUG_MAX_LENGTH = 50;
    public const NAME_MAX_LENGTH = 100;

    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @return Paginated<Community>
     *
     * @throws GurbApiException
     */
    public function list(?int $page = null, ?int $limit = null, ?string $search = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'admin/sdk/communities', [
                'page' => $page,
                'limit' => $limit,
                'search' => $search,
            ]),
            Community::fromArray(...),
        );
    }

    /**
     * Create a community outright, with no approval step.
     *
     * THIS IS THE OPERATION THE WHOLE SUPER-ADMIN KEY EXISTS TO PROTECT. Anyone
     * holding this credential can populate the platform with communities nobody
     * asked for, so treat the key the way you would treat a database password:
     * one holder, injected from a secret store, never in a repository, rotated
     * when staff change.
     *
     * The ordinary path is the other one — `GurbClient::requestCommunityCreation()`
     * files a request and a human approves it. Reach for this only when you are
     * the human.
     *
     * TWO ARGUMENTS AND ONE OPTION. There is no `$description`, no `$type` and
     * no `$ownerUserId`, because the endpoint does not honour them: visibility
     * is decided server-side (PRIVATE) and, by default, the owner is taken from
     * the credential that made the call. Accepting the others here and dropping
     * them on the floor would be worse than not offering them — a caller who
     * passes `type: 'PUBLIC'` and gets a private community has been lied to by
     * an API that looked like it was listening. Set the rest afterwards through
     * the dashboard or the community settings endpoints.
     *
     * `$owner` is the one exception, and it is not a field the server ignores:
     * it names a person YOUR identity provider vouches for, and the community is
     * founded by them instead of by you. Use it when the community and its
     * founder are being created in the same breath — "sign in with Okta, get a
     * community". Read ExternalOwner before you use it; the rules about when an
     * assertion may attach to an account that already exists are the whole point
     * of that class.
     *
     * @param string $name The human-facing name, usually Arabic. Trimmed before
     *                     sending; at most self::NAME_MAX_LENGTH characters,
     *                     counted as characters and not bytes.
     * @param string $slug The latin identifier used in URLs. Validated here,
     *                     before any HTTP call — see assertValidSlug().
     *
     * @param ExternalOwner|null $owner Optional. The person who should found this
     *                                   community, as asserted by an identity
     *                                   provider registered in Gurb. Omit it and
     *                                   the credential's own user founds it.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) if the name, slug or
     *                          owner breaks a rule, with NO request made. Or,
     *                          from the server, VALIDATION_ERROR when the slug is
     *                          already taken — slugs are unique platform-wide, so
     *                          that is a normal outcome and not an exceptional
     *                          one. With an `$owner`, also:
     *
     *                            400 EXTERNAL_IDENTITY_PROVIDER_UNKNOWN
     *                                — the slug names nothing registered and
     *                                  active. Yours to fix.
     *                            409 EXTERNAL_IDENTITY_EMAIL_UNVERIFIED_AT_PROVIDER
     *                                — somebody already holds that address and
     *                                  you did not assert emailVerified: true.
     *                            409 EXTERNAL_IDENTITY_EMAIL_UNVERIFIED_AT_GURB
     *                                — they hold it but never verified it here.
     *                                  Not yours to fix; they must verify.
     *                            409 EXTERNAL_IDENTITY_MANUAL_LINK_REQUIRED
     *                                — that account cannot be linked
     *                                  automatically. Contact the platform team.
     *
     *                          In EVERY one of those cases NO community was
     *                          created. The identity is resolved before any
     *                          schema is minted, precisely so a refused owner
     *                          does not leave an ownerless tenant behind.
     */
    public function create(string $name, string $slug, ?ExternalOwner $owner = null): Community
    {
        $trimmedName = \trim($name);
        self::assertValidName($trimmedName, $name);
        self::assertValidSlug($slug);
        $owner?->assertValid();

        $body = [
            // Exactly these keys. Anything else the server would ignore, and an
            // ignored field in a request body is a promise nobody is keeping.
            'name' => $trimmedName,
            'slug' => $slug,
        ];

        if ($owner !== null) {
            $body['owner'] = $owner->toArray();
        }

        return Community::fromArray(
            $this->requester->request('POST', 'admin/sdk/communities', body: $body),
        );
    }

    /**
     * @param string $trimmed The value that will actually be sent.
     * @param string $original What the caller passed, for a message that quotes
     *                         what they typed rather than what we made of it.
     *
     * @throws GurbApiException
     */
    private static function assertValidName(string $trimmed, string $original): void
    {
        if ($trimmed === '') {
            throw GurbApiException::localValidation(
                $original === ''
                    ? 'A community name is required.'
                    : 'A community name cannot be only whitespace.',
            );
        }

        $length = self::characterCount($trimmed);
        if ($length === null) {
            // Rejected rather than sent: a malformed byte sequence would be
            // stored, rendered and re-served, and the corruption would be
            // blamed on whichever screen displayed it last.
            throw GurbApiException::localValidation('The community name is not valid UTF-8.');
        }

        if ($length > self::NAME_MAX_LENGTH) {
            throw GurbApiException::localValidation(\sprintf(
                'A community name may be at most %d characters; this one is %d.',
                self::NAME_MAX_LENGTH,
                $length,
            ));
        }
    }

    /**
     * Every slug rule, each with a message naming the rule it broke.
     *
     * "Invalid slug" tells an integrator nothing — they are looking at a string
     * that looks fine to them. Each branch below says which rule, and shows the
     * value, so the fix is obvious from the log line alone.
     *
     * @throws GurbApiException
     */
    private static function assertValidSlug(string $slug): void
    {
        if ($slug === '') {
            throw GurbApiException::localValidation(
                'A community slug is required. Slugs are the latin identifier in the URL, like "book-club".',
            );
        }

        // Charset first, so everything after it is guaranteed ASCII and a byte
        // length is a character length.
        if (\preg_match('/[^a-z0-9-]/', $slug) === 1) {
            $lowered = \strtolower($slug);
            if ($lowered !== $slug && \preg_match('/[^a-z0-9-]/', $lowered) !== 1) {
                // By far the most common mistake, and the most annoying one to
                // diagnose from a generic message. Hand over the answer.
                throw GurbApiException::localValidation(\sprintf(
                    'Community slugs are lowercase: "%s" is not valid, but "%s" would be. '
                    . 'A slug may contain lowercase letters (a-z), digits (0-9) and hyphens only.',
                    $slug,
                    $lowered,
                ));
            }

            throw GurbApiException::localValidation(\sprintf(
                '"%s" is not a valid community slug. A slug may contain lowercase letters (a-z), '
                . 'digits (0-9) and hyphens only — no spaces, accents or Arabic. The Arabic name goes in $name.',
                $slug,
            ));
        }

        if (\str_starts_with($slug, '-') || \str_ends_with($slug, '-')) {
            throw GurbApiException::localValidation(\sprintf(
                'A community slug may not start or end with a hyphen: "%s".',
                $slug,
            ));
        }

        if (\str_contains($slug, '--')) {
            throw GurbApiException::localValidation(\sprintf(
                'A community slug may not contain consecutive hyphens: "%s".',
                $slug,
            ));
        }

        $length = \strlen($slug);
        if ($length < self::SLUG_MIN_LENGTH || $length > self::SLUG_MAX_LENGTH) {
            throw GurbApiException::localValidation(\sprintf(
                'A community slug must be %d to %d characters; "%s" is %d.',
                self::SLUG_MIN_LENGTH,
                self::SLUG_MAX_LENGTH,
                $slug,
                $length,
            ));
        }
    }

    /**
     * Characters, not bytes — and without requiring ext-mbstring.
     *
     * `strlen('نادي')` is 8, not 4. Measuring an Arabic name in bytes would
     * reject a perfectly ordinary 60-character name for being "over 100", which
     * is the kind of bug that only shows up in the language the product is
     * actually used in. PCRE with /u counts code points and is always
     * available, where mbstring is an extension this SDK deliberately does not
     * require for one length check.
     *
     * @return int|null Null when the string is not valid UTF-8.
     */
    private static function characterCount(string $value): ?int
    {
        $count = \preg_match_all('/./us', $value);

        return $count === false ? null : $count;
    }
}
