<?php

declare(strict_types=1);

namespace Gurb\Input;

use Gurb\GurbApiException;

/**
 * A person your identity provider vouches for, who should own a new community.
 *
 * WHY THIS EXISTS
 *
 * A community must have a founding administrator, and that has to be a real Gurb
 * account. If you are building "sign in with Okta, get a community", your user
 * has no Gurb account yet and there is no public endpoint to create one — that
 * is deliberate, because an endpoint that mints accounts from an e-mail address
 * alone is a far bigger hole than this one. Passing an owner alongside the
 * community lets the two happen together, under rules the server enforces.
 *
 * THE BINDING IS (provider, externalUserId) — NEVER THE E-MAIL
 *
 * This is the single most important thing to understand about this class.
 *
 * The pair above is the identity. The e-mail is a LABEL attached to it. That
 * distinction is what makes the following two things both work:
 *
 *   - your user changes their address from a.ali@corp.com to ahmed.ali@corp.com,
 *     and keeps the same Gurb account, because the subject did not change;
 *   - a NEW employee inherits the recycled address a.ali@corp.com, and gets a
 *     NEW Gurb account, because their subject is new.
 *
 * Neither is achievable if the e-mail is the key. And the second is not a
 * hypothetical: employers reassign addresses constantly, and keying on e-mail
 * would silently hand the leaver's account — with its communities and its admin
 * roles — to their replacement.
 *
 * WHAT THE SERVER WILL REFUSE
 *
 * If somebody in Gurb ALREADY holds the address you assert, the server is being
 * asked to give their existing account to whoever controls a subject id at your
 * provider. It will only do so when all four of these hold:
 *
 *   1. `$provider` names a provider registered AND active in Gurb;
 *   2. you assert `emailVerified: true`;
 *   3. the existing Gurb account has itself verified that address;
 *   4. the existing account is not a platform super admin.
 *
 * Otherwise you get a 409 and no write happens. When it does link, Gurb e-mails
 * the account's owner — that notice is the mitigation, so expect your users to
 * receive it and do not treat it as a bug.
 *
 * @see \Gurb\Model\CommunityOwner for what comes back.
 */
final class ExternalOwner
{
    /**
     * @param string $provider       The `slug` of an identity provider already
     *                               registered and active in Gurb — "okta",
     *                               "entra", whatever your deployment configured.
     *                               A slug you invent is refused with a 400; the
     *                               server never trusts this string on its own,
     *                               it resolves it to a row first.
     * @param string $externalUserId Your provider's own stable id for this person
     *                               — Okta's `sub`. Opaque to Gurb: never parsed,
     *                               never compared case-insensitively. STABLE
     *                               FOREVER is not advice, it is the contract:
     *                               reusing one for a different human hands them
     *                               the first person's account.
     * @param string $email          The address the provider asserts.
     * @param bool   $emailVerified  Whether YOUR provider verified it. There is
     *                               no default, and that is on purpose: this
     *                               value decides whether an incoming identity
     *                               may join an account that already exists, and
     *                               a default would let a caller reach the
     *                               dangerous branch without ever having thought
     *                               about it. Say false if you are unsure — the
     *                               cost is a refusal, not a takeover.
     * @param string|null $firstName Optional. Falls back to the local part of the
     *                               e-mail, because the underlying column is NOT
     *                               NULL and a row has to exist.
     * @param string|null $familyName Optional. Empty when absent.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $externalUserId,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly ?string $firstName = null,
        public readonly ?string $familyName = null,
    ) {
    }

    /**
     * Catch locally what would otherwise be a round trip and a 400.
     *
     * Deliberately shallow: this checks that the fields are PRESENT and not
     * blank, and nothing more. It does NOT check that the provider exists (only
     * the server knows), and it does not police the e-mail beyond an obvious
     * shape — a strict RFC 5322 pattern rejects addresses real mail servers
     * accept, and refusing a customer's valid internal address to feel thorough
     * is a worse failure than one extra HTTP call.
     *
     * @throws GurbApiException VALIDATION_ERROR, status 0, with no request made.
     */
    public function assertValid(): void
    {
        if (\trim($this->provider) === '') {
            throw GurbApiException::localValidation(
                'owner.provider is required: the slug of an identity provider registered in Gurb.',
            );
        }

        if (\trim($this->externalUserId) === '') {
            throw GurbApiException::localValidation(
                'owner.externalUserId is required: your provider\'s own stable id for this person.',
            );
        }

        $email = \trim($this->email);
        $at = \strpos($email, '@');
        if ($email === '' || $at === false || $at === 0 || $at === \strlen($email) - 1) {
            throw GurbApiException::localValidation(
                \sprintf('owner.email is not a valid address: "%s".', $this->email),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'provider' => \trim($this->provider),
            'externalUserId' => \trim($this->externalUserId),
            'email' => \trim($this->email),
            // Always sent, never omitted. An absent field would be read by the
            // server as "not verified" — the same outcome — but sending it
            // explicitly keeps the wire honest about what was asserted, which
            // is what an incident investigator reads later.
            'emailVerified' => $this->emailVerified,
        ];

        if ($this->firstName !== null && \trim($this->firstName) !== '') {
            $payload['firstName'] = \trim($this->firstName);
        }

        if ($this->familyName !== null && \trim($this->familyName) !== '') {
            $payload['familyName'] = \trim($this->familyName);
        }

        return $payload;
    }
}
