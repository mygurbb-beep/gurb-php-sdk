<?php

declare(strict_types=1);

namespace Gurb;

/**
 * Stable, machine-readable error codes.
 *
 * Plain string constants rather than a PHP enum: `$e->code()` is compared against
 * literals in host code and appears in logs, and an enum would force every
 * consumer to import this class to write a comparison. The constants are here
 * for discoverability and typo-safety, not as a required vocabulary.
 *
 * Backend messages are English by project convention; consumers own translation.
 */
final class GurbErrorCode
{
    public const INVALID_API_KEY = 'INVALID_API_KEY';
    public const API_KEY_REVOKED = 'API_KEY_REVOKED';
    public const FORBIDDEN_SURFACE = 'FORBIDDEN_SURFACE';
    public const INSUFFICIENT_SCOPE = 'INSUFFICIENT_SCOPE';
    public const FEATURE_NOT_AVAILABLE = 'FEATURE_NOT_AVAILABLE';
    public const NOT_FOUND = 'NOT_FOUND';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';

    // Partner Sign-In (1.8.0) — refusals in a community that admits members only through its host site.
    /** The community admits members only through its host site, and this key is not the host's. */
    public const JOIN_THROUGH_HOST_ONLY = 'JOIN_THROUGH_HOST_ONLY';
    /** The host blocked this member (an access event or the nightly snapshot): show "membership inactive". */
    public const HOST_MEMBER_BLOCKED = 'HOST_MEMBER_BLOCKED';
    /** The member was removed or banned in this community. */
    public const MEMBER_NOT_ACTIVE = 'MEMBER_NOT_ACTIVE';
    /** The Gurb account behind this member is deactivated or expired. */
    public const ACCOUNT_NOT_USABLE = 'ACCOUNT_NOT_USABLE';
    /** A competing request claimed the same identity at the same moment — retry once. */
    public const EXTERNAL_IDENTITY_MANUAL_LINK_REQUIRED = 'EXTERNAL_IDENTITY_MANUAL_LINK_REQUIRED';
    /** The identity in the request cannot be acted on (bad externalUserId / email). */
    public const EXTERNAL_IDENTITY_INVALID_INPUT = 'EXTERNAL_IDENTITY_INVALID_INPUT';

    // Event reminders + content invitations (1.9.0). None of these is helped by retrying now.
    /** A manual reminder for this event went out less than 10 minutes ago. */
    public const EVENT_REMINDER_COOLDOWN = 'EVENT_REMINDER_COOLDOWN';
    /** The event is a draft, cancelled or completed. */
    public const EVENT_NOT_REMINDABLE = 'EVENT_NOT_REMINDABLE';
    /** The event has already started. */
    public const EVENT_ALREADY_STARTED = 'EVENT_ALREADY_STARTED';
    /** Nobody is attending the event yet. */
    public const EVENT_REMINDER_NO_RECIPIENTS = 'EVENT_REMINDER_NO_RECIPIENTS';
    /** The key's owner sent 100 invitations in the last 24 hours. */
    public const INVITES_DAILY_LIMIT = 'INVITES_DAILY_LIMIT';
    /** The key's owner is restricted (suspended, or blocked from posting) in this community. */
    public const RESTRICTION_BLOCKED = 'RESTRICTION_BLOCKED';

    public const NETWORK_ERROR = 'NETWORK_ERROR';
    public const UNKNOWN = 'UNKNOWN';

    /**
     * Codes the backend is allowed to declare in its `error` field.
     *
     * NETWORK_ERROR and UNKNOWN are absent on purpose: those are produced by
     * this SDK, never received from the server.
     *
     * @var list<string>
     */
    public const DECLARABLE_BY_BACKEND = [
        self::INVALID_API_KEY,
        self::API_KEY_REVOKED,
        self::FORBIDDEN_SURFACE,
        self::INSUFFICIENT_SCOPE,
        self::FEATURE_NOT_AVAILABLE,
        self::NOT_FOUND,
        self::RATE_LIMITED,
        self::VALIDATION_ERROR,
        self::JOIN_THROUGH_HOST_ONLY,
        self::HOST_MEMBER_BLOCKED,
        self::MEMBER_NOT_ACTIVE,
        self::ACCOUNT_NOT_USABLE,
        self::EXTERNAL_IDENTITY_MANUAL_LINK_REQUIRED,
        self::EXTERNAL_IDENTITY_INVALID_INPUT,
        self::EVENT_REMINDER_COOLDOWN,
        self::EVENT_NOT_REMINDABLE,
        self::EVENT_ALREADY_STARTED,
        self::EVENT_REMINDER_NO_RECIPIENTS,
        self::INVITES_DAILY_LIMIT,
        self::RESTRICTION_BLOCKED,
    ];

    private function __construct()
    {
    }
}
