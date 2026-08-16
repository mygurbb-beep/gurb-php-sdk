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

    /**
     * Create a project in the SECOND projects module.
     *
     * TWO GATES STAND IN FRONT OF EVERY CALL HERE, not one, and they refuse with
     * different codes because they send you to different people:
     *
     *   - `FEATURE_NOT_AVAILABLE` — the community's PLAN does not include
     *     Projects-2. Talk to whoever pays the bill.
     *   - `FEATURE_DISABLED` — the plan includes it and a community ADMIN has
     *     switched the module off in the dashboard. Talk to them.
     *
     * Both apply to reads as well as writes. Neither is a permission and no
     * grant fixes either.
     *
     * The permission set is identical to `ProjectsResource` but the NAMES are
     * not: `projects2:manage`, `projects2:create` or `projects2:moderate`. A
     * grant on the first module does nothing here. A plain member holds none of
     * them — `MEMBER` is an empty permission set in Gurb — so a key whose owner
     * was granted nothing reads everything and publishes nothing.
     *
     * ⚠️ Same `$name` in / `title` out asymmetry as the first module, for the
     * same reason: the write contract accepts `name`, every response calls it
     * `title`.
     *
     * @param array<string, mixed> $options Identical to `ProjectsResource::create()`.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) with NO request made
     *                          when name or description is blank.
     */
    public function create(string $name, string $description, array $options = []): Project
    {
        $trimmedName = \trim($name);
        $trimmedDescription = \trim($description);

        if ($trimmedName === '' || $trimmedDescription === '') {
            throw GurbApiException::localValidation('A project needs a name and a description.');
        }

        // The trimmed values are what get SENT, not merely what got validated.
        $body = \array_merge($options, [
            'name' => $trimmedName,
            'description' => $trimmedDescription,
        ]);

        return Project::fromArray($this->requester->request('POST', 'sdk/projects2', body: $body));
    }

    /**
     * Update a project in the second module.
     *
     * Authorship is checked FIRST — the key's owner may always edit what it
     * created. Anyone else's needs `projects2:manage`, `projects2:create`,
     * `projects2:moderate` or `projects2:edit`. Same `null`-clears-a-nullable-
     * field rules as the first module.
     *
     * @param array<string, mixed> $fields Only what you want changed. An empty
     *                                     array is a 400, not a no-op.
     *
     * @throws GurbApiException
     */
    public function update(string $projectId, array $fields): Project
    {
        return Project::fromArray($this->requester->request(
            'PATCH',
            // rawurlencode, so a slash inside a caller-supplied id cannot walk
            // the request onto a path this SDK never meant to call.
            'sdk/projects2/' . \rawurlencode($projectId),
            body: $fields,
        ));
    }

    /**
     * Delete a project in the second module.
     *
     * `projects2:create` drops, `projects2:edit` stays, authorship still counts —
     * the same shape as the first module. A HARD delete with no webhook.
     *
     * @throws GurbApiException
     */
    public function delete(string $projectId): void
    {
        $this->requester->request('DELETE', 'sdk/projects2/' . \rawurlencode($projectId));
    }
}
