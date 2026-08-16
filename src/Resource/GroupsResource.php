<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Group;
use Gurb\Model\Paginated;

final class GroupsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * Every group in this community, private ones included.
     *
     * The list is not filtered by visibility — see `Group::$isPrivate` for why,
     * and for the responsibility that comes with it.
     *
     * @return Paginated<Group>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when groups are not on this
     *                          community's plan — the module is a plan toggle,
     *                          so this is an expected outcome, not a bug.
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/groups', ['page' => $page, 'limit' => $limit]),
            Group::fromArray(...),
        );
    }
}
