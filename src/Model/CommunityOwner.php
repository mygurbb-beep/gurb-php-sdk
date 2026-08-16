<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * Who ended up owning a newly created community, and HOW the server got there.
 *
 * Present only when you passed an `ExternalOwner`. The three outcomes are
 * operationally different and you cannot tell them apart from the outside, so
 * branch on `$outcome` rather than assuming — the difference decides what you
 * have to do next, and in one case it decides whether you should be alarmed.
 */
final class CommunityOwner
{
    /** The identity was already bound to this account. Nothing changed. */
    public const OUTCOME_MATCHED = 'MATCHED';

    /**
     * A brand-new, passwordless Gurb account now exists.
     *
     * Its owner CANNOT SIGN IN YET by any means you have not arranged: there is
     * no password, and no verification mail was sent. Send them in through your
     * own provider, or through Gurb's password-reset flow.
     */
    public const OUTCOME_CREATED = 'CREATED';

    /**
     * An account that ALREADY had a history — content, communities, possibly
     * admin roles — is now reachable through your identity provider.
     *
     * Gurb has e-mailed that account's owner to tell them so; that notice is the
     * safeguard, not a courtesy. If you did not expect this outcome, you
     * asserted an address belonging to somebody who was already on the platform.
     * Worth logging and worth alerting on.
     */
    public const OUTCOME_LINKED = 'LINKED';

    /** No owner was asserted; the community belongs to the calling credential. */
    public const OUTCOME_CALLER = 'CALLER';

    private function __construct(
        public readonly string $userId,
        /** One of the OUTCOME_* constants above. */
        public readonly string $outcome,
    ) {
    }

    /** True when a Gurb account that already existed was bound to your provider. */
    public function linkedExistingAccount(): bool
    {
        return $this->outcome === self::OUTCOME_LINKED;
    }

    /** True when this call brought a new account into existence. */
    public function createdNewAccount(): bool
    {
        return $this->outcome === self::OUTCOME_CREATED;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: Decode::str($data, 'userId'),
            // Defaulted rather than required: a server that adds a fourth
            // outcome must not break a client that has not been updated, and an
            // unknown string is still readable in a log.
            outcome: Decode::str($data, 'outcome', self::OUTCOME_CALLER),
        );
    }
}
