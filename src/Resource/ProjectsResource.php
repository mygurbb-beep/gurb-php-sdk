<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Paginated;
use Gurb\Model\Project;

/**
 * The first projects module.
 *
 * There is a second one — `Projects2Resource`, on `$gurb->projects2`. It is a
 * PARALLEL MODULE, NOT A NEWER VERSION of this one, and both may be enabled for
 * the same community at once. So this class is not deprecated and reading only
 * `projects` is not "reading the current one"; it is reading one of two places
 * a community's projects may live.
 */
final class ProjectsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @return Paginated<Project>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when the projects module is
     *                          not on this community's plan.
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/projects', ['page' => $page, 'limit' => $limit]),
            Project::fromArray(...),
        );
    }
}
