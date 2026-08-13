<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\CommunityRole;
use Gurb\GurbApiException;
use Gurb\Input\BulkMemberInput;
use Gurb\Internal\Requester;
use Gurb\Model\BulkMemberResult;
use Gurb\Model\Member;
use Gurb\Model\MemberPermissions;
use Gurb\Model\Paginated;

/**
 * Read and manage the membership of the community this key belongs to.
 *
 * This class used to be read-only, and the write methods below are the deliberate
 * widening of that: managing membership is the reason most integrations exist
 * (your user table is the source of truth, Gurb mirrors it). The limits that
 * remain are the ones that matter — no method takes a `communityId`, so a key
 * still reaches exactly one community; and `CommunityRole` has no platform role
 * in it, so nothing here can escalate anyone off this community.
 */
final class MembersResource
{
    /**
     * The most members one call may carry.
     *
     * Public so you can chunk against it rather than hard-coding 500:
     *
     *     foreach (array_chunk($rows, MembersResource::BULK_MEMBER_LIMIT) as $chunk) {
     *         $gurb->members->bulkUpsert($chunk);
     *     }
     */
    public const BULK_MEMBER_LIMIT = 500;

    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @return Paginated<Member>
     *
     * @throws GurbApiException
     */
    public function list(?int $page = null, ?int $limit = null, ?CommunityRole $role = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/members', [
                'page' => $page,
                'limit' => $limit,
                'role' => $role?->value,
            ]),
            Member::fromArray(...),
        );
    }

    /**
     * @param string $memberId A `CommunityMember.id`, not a `User.id`. The two
     *                         are different ids and every route here takes the
     *                         membership one — `Member::$memberId`, not
     *                         `Member::$userId`.
     *
     * @throws GurbApiException
     */
    public function get(string $memberId): Member
    {
        return Member::fromArray(
            $this->requester->request('GET', 'sdk/members/' . \rawurlencode($memberId)),
        );
    }

    /**
     * Change a member's role.
     *
     * The platform caps this at your key's own authority: a key minted by a
     * MODERATOR cannot produce an ADMIN. That check lives on the server, because
     * a client-side one is a suggestion and not a control — and it is evaluated
     * against the LIVE membership of whoever minted the key, which is what makes
     * a key stop being able to do this the moment its owner is demoted.
     *
     * @throws GurbApiException
     */
    public function setRole(string $memberId, CommunityRole $role): Member
    {
        return Member::fromArray($this->requester->request(
            'PATCH',
            'sdk/members/' . \rawurlencode($memberId) . '/role',
            body: ['role' => $role->value],
        ));
    }

    /**
     * What this member can actually do: what the role grants, plus the
     * individual grants, minus the individual revokes.
     *
     * @throws GurbApiException
     */
    public function getPermissions(string $memberId): MemberPermissions
    {
        return MemberPermissions::fromArray(
            $this->requester->request('GET', 'sdk/members/' . \rawurlencode($memberId) . '/permissions'),
        );
    }

    /**
     * Grant and/or revoke permissions for one member, on top of their role.
     *
     * ONE REQUEST CARRYING BOTH LISTS, and this is the design decision in this
     * class worth arguing about. Two methods (`grant()` then `revoke()`) would
     * read more naturally and would be wrong: between the two calls the member
     * exists in a state you never asked for — the grant applied and the revoke
     * not — and if the second call fails, times out, or the PHP process is
     * killed by a deploy, they stay there. Two HTTP calls cannot be made atomic
     * from outside the server. One can.
     *
     * Concretely: promoting someone to "editor" by granting EDIT_ANY_POST and
     * revoking DELETE_ANY_POST must not leave a window where they can do both.
     *
     * SENDING THE SAME PERMISSION IN BOTH LISTS IS REJECTED, not resolved. There
     * is no correct guess at what was meant — grant-then-revoke and
     * revoke-then-grant are opposite outcomes and array order is not intent — so
     * asking the server would only move the guess somewhere else.
     *
     * @param list<string> $grant   Permission names. `Permission::` has constants
     *                              for the common ones; any string is accepted,
     *                              because the platform's catalogue grows.
     * @param list<string> $revoke
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) before any HTTP call
     *                          if the lists conflict or are both empty.
     */
    public function updatePermissions(string $memberId, array $grant = [], array $revoke = []): MemberPermissions
    {
        $conflicting = \array_values(\array_intersect($grant, $revoke));
        if ($conflicting !== []) {
            throw GurbApiException::localValidation(
                'Cannot grant and revoke the same permission in one call: ' . \implode(', ', $conflicting),
            );
        }

        if ($grant === [] && $revoke === []) {
            // Not a harmless no-op: an empty change set almost always means the
            // caller built the lists from a filter that matched nothing, and
            // silently sending a request that changes nothing would hide that.
            throw GurbApiException::localValidation(
                'updatePermissions needs at least one permission to grant or revoke.',
            );
        }

        return MemberPermissions::fromArray($this->requester->request(
            'PATCH',
            'sdk/members/' . \rawurlencode($memberId) . '/permissions',
            body: [
                // array_values keeps these JSON arrays. A PHP array with gaps in
                // its keys — the usual result of array_filter() upstream —
                // encodes as a JSON *object*, and the server would reject
                // `{"0":"CREATE_POST"}` where it expected a list.
                'grant' => \array_values($grant),
                'revoke' => \array_values($revoke),
            ],
        ));
    }

    /**
     * Remove a member from this community.
     *
     * This is a membership removal, NOT an account deletion: the person keeps
     * their Gurb account and their name stays on everything they already posted.
     * If they come back through an embed session with the same
     * `externalUserId`, they are the same person again.
     *
     * @throws GurbApiException
     */
    public function remove(string $memberId): void
    {
        // Returns `data: null`, so there is nothing to map and nothing to
        // return. Typed void rather than handing back an empty array a caller
        // might mistake for a result.
        $this->requester->request('DELETE', 'sdk/members/' . \rawurlencode($memberId));
    }

    /**
     * Add or update many members in one call.
     *
     * MATCHED ON `externalUserId`, which is what makes this a sync rather than
     * an import: sending the same list twice updates, it does not duplicate. So
     * this is safe to run nightly against your whole user table, and safe to
     * retry after a timeout — that second property is worth as much as the
     * first, because you cannot tell a timeout that landed from one that did not.
     *
     * Individual rows can fail without failing the batch. Check
     * `$result->failed`; a 200 does not mean every row landed.
     *
     * Three things are refused here, before anything is sent:
     *
     *   - an empty list — nothing to do, and almost always an upstream bug
     *   - more than BULK_MEMBER_LIMIT rows — a request that large is likely to
     *     die at a proxy timeout, leaving you with no way to know which half
     *     landed; a clean local failure is strictly better than that
     *   - a duplicate externalUserId inside one batch — the rows would race
     *     against each other server-side and the winner would be arbitrary
     *
     * @param list<BulkMemberInput> $members
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) for any of the above,
     *                          with no HTTP call made.
     */
    public function bulkUpsert(array $members): BulkMemberResult
    {
        if ($members === []) {
            throw GurbApiException::localValidation('bulkUpsert needs at least one member.');
        }

        $count = \count($members);
        if ($count > self::BULK_MEMBER_LIMIT) {
            throw GurbApiException::localValidation(\sprintf(
                'bulkUpsert accepts at most %d members per call; you sent %d. Chunk the list and send it in batches.',
                self::BULK_MEMBER_LIMIT,
                $count,
            ));
        }

        $seen = [];
        $rows = [];
        foreach ($members as $member) {
            if (isset($seen[$member->externalUserId])) {
                // Name the offending id. "Duplicate entry" in a batch of 500
                // rows is a message you cannot act on.
                throw GurbApiException::localValidation(
                    'Duplicate externalUserId in the same batch: ' . $member->externalUserId,
                );
            }
            $seen[$member->externalUserId] = true;
            $rows[] = $member->toArray();
        }

        return BulkMemberResult::fromArray(
            $this->requester->request('POST', 'sdk/members/bulk', body: ['members' => $rows]),
        );
    }
}
