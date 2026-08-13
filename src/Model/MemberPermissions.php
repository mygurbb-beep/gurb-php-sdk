<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\CommunityRole;
use Gurb\Internal\Decode;

/**
 * What one member can actually do, and where each part of it came from.
 *
 * Three lists rather than one because "can they post?" and "why can they post?"
 * are different questions, and only the second one tells you what to change:
 *
 *   effective = (what the role grants) + granted - revoked
 *
 * So removing CREATE_POST from someone whose ROLE grants it means adding it to
 * `revoked`, not removing it from `granted` — it was never in `granted`. That
 * distinction is the reason this object does not just return a flat list.
 */
final class MemberPermissions
{
    /**
     * @param list<string> $effective Everything in force right now.
     * @param list<string> $granted   Added to this member specifically, on top of the role.
     * @param list<string> $revoked   Taken away from this member specifically, despite the role.
     */
    private function __construct(
        public readonly string $memberId,
        public readonly string $userId,
        /**
         * 'ADMIN' | 'MODERATOR' | 'MEMBER'.
         *
         * A string on the way in even though `setRole()` takes the enum on the
         * way out. Strict where you write, tolerant where you read: an unknown
         * role from a newer server should render oddly, not throw. `role()`
         * gives you the enum when you need to compare.
         */
        public readonly string $role,
        public readonly array $effective,
        public readonly array $granted,
        public readonly array $revoked,
    ) {
    }

    /** Ask about one permission without caring where it came from. */
    public function can(string $permission): bool
    {
        return \in_array($permission, $this->effective, true);
    }

    /** The enum form, or null for a role this SDK version predates. */
    public function role(): ?CommunityRole
    {
        return CommunityRole::tryFrom($this->role);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            memberId: Decode::str($data, 'memberId'),
            userId: Decode::str($data, 'userId'),
            role: Decode::str($data, 'role', CommunityRole::Member->value),
            // strList drops non-strings rather than coercing them. A permission
            // check must never accidentally compare against "42".
            effective: Decode::strList($data, 'effective'),
            granted: Decode::strList($data, 'granted'),
            revoked: Decode::strList($data, 'revoked'),
        );
    }
}
