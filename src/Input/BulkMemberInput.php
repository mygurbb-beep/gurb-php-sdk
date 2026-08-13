<?php

declare(strict_types=1);

namespace Gurb\Input;

use Gurb\CommunityRole;

/**
 * One row of a bulk member sync.
 *
 * A class rather than an associative array so a typo in a key ('displayname')
 * is a compile-time-ish error instead of a row that comes back in `failed` with
 * "displayName is required" after you have already sent 500 of them.
 */
final class BulkMemberInput
{
    /**
     * @param string $externalUserId YOUR id for this person, opaque to Gurb and
     *                               stable forever. It is the join key: the whole
     *                               "sync, not import" property of this endpoint
     *                               rests on it, and reusing one for a different
     *                               human hands them the first person's account.
     *                               Never an email or a username someone can change.
     * @param CommunityRole|null $role Null means MEMBER, decided by the server.
     *                                 A key can never assign a role above its own
     *                                 authority, and the enum has no platform role
     *                                 to assign in the first place.
     */
    public function __construct(
        public readonly string $externalUserId,
        public readonly string $displayName,
        public readonly ?string $email = null,
        public readonly ?CommunityRole $role = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $row = [
            'externalUserId' => $this->externalUserId,
            'displayName' => $this->displayName,
        ];

        // Omitted, not null — same reason as everywhere else in this SDK.
        if ($this->email !== null) {
            $row['email'] = $this->email;
        }
        if ($this->role !== null) {
            $row['role'] = $this->role->value;
        }

        return $row;
    }
}
