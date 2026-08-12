<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Blog;
use Gurb\Model\Paginated;

final class BlogsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @return Paginated<Blog>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when blogs are not on this
     *                          community's plan — the module is a plan toggle,
     *                          so this is an expected outcome, not a bug.
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/blogs', ['page' => $page, 'limit' => $limit]),
            Blog::fromArray(...),
        );
    }
}
