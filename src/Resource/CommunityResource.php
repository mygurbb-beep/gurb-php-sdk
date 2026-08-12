<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Community;

final class CommunityResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * The community this key is pinned to.
     *
     * Note there is no `$communityId` argument anywhere in this SDK. The key
     * carries the community, so a leaked key damages exactly one community and
     * can never be pointed at another one.
     *
     * @throws GurbApiException
     */
    public function get(): Community
    {
        return Community::fromArray($this->requester->request('GET', 'sdk/community'));
    }
}
