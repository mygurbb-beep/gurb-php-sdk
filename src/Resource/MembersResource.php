<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Member;
use Gurb\Model\Paginated;

final class MembersResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * Read-only. There is no method here to add, remove or re-role a member:
     * the SDK's write surface is deliberately limited to minting embed
     * sessions, so a stolen key cannot restructure a community's membership.
     *
     * @return Paginated<Member>
     *
     * @throws GurbApiException
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/members', ['page' => $page, 'limit' => $limit]),
            Member::fromArray(...),
        );
    }
}
