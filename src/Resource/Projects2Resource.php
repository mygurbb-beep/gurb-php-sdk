<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Paginated;
use Gurb\Model\Project;

/**
 * The second projects module.
 *
 * A GENUINE PARALLEL MODULE, NOT A VERSION — and the "2" in the name is the
 * platform's, not a deprecation marker this SDK invented. Some communities run
 * this one instead of `projects`, some run both, and nothing in a response tells
 * you which. So the SDK exposes both endpoints rather than guessing: a client
 * that silently read only one would return an empty list for half the
 * platform's communities, which is the worst possible failure — it looks like
 * "this community has no projects" rather than "you asked the wrong module".
 *
 * If you do not know which a community uses, read both and merge. They publish
 * the same shape (`Project`), so merging costs nothing.
 *
 * A separate class rather than a `$module` argument on `ProjectsResource` for
 * the same reason the TypeScript SDK declares two resources: these are two
 * endpoints, and making one of them a parameter would invite a caller to
 * construct a third that does not exist.
 */
final class Projects2Resource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @return Paginated<Project>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when the second projects
     *                          module is not on this community's plan. Expect
     *                          this routinely: most communities enable one
     *                          module, not both, so a 403 here is a normal
     *                          answer and not a sign of a broken key.
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/projects2', ['page' => $page, 'limit' => $limit]),
            Project::fromArray(...),
        );
    }
}
