<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Album;
use Gurb\Model\Paginated;

final class AlbumsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @return Paginated<Album>
     *
     * @throws GurbApiException
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/albums', ['page' => $page, 'limit' => $limit]),
            Album::fromArray(...),
        );
    }
}
