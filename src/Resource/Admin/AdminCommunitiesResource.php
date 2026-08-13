<?php

declare(strict_types=1);

namespace Gurb\Resource\Admin;

use Gurb\GurbApiException;
use Gurb\Input\CreateCommunityInput;
use Gurb\Internal\Requester;
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
     * @throws GurbApiException VALIDATION_ERROR when the slug is already taken.
     *                          Slugs are unique platform-wide, so this is a
     *                          normal outcome and not an exceptional one.
     */
    public function create(CreateCommunityInput $input): Community
    {
        return Community::fromArray(
            $this->requester->request('POST', 'admin/sdk/communities', body: $input->toArray()),
        );
    }
}
