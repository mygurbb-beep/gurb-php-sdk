<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Paginated;
use Gurb\Model\Tweet;

final class TweetsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @return Paginated<Tweet>
     *
     * @throws GurbApiException
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/tweets', ['page' => $page, 'limit' => $limit]),
            Tweet::fromArray(...),
        );
    }
}
