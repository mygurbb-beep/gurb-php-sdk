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

    /**
     * Create a project.
     *
     * Requires the `projects` feature on the community's plan. A community
     * without it answers 403 FEATURE_NOT_AVAILABLE on EVERY call here, reads
     * included — an entitlement, not a permission, and no grant will fix it.
     *
     * The key's owner needs `projects:manage`, `projects:create` OR
     * `projects:moderate`. That third one is unusual for a create and is copied,
     * not tidied: every write in this SDK uses the SAME permission set Gurb's own
     * route checks, so the requirement differs per section and cannot be
     * unified. A plain member holds none of them — `MEMBER` is an empty
     * permission set in Gurb — so a key whose owner was granted nothing reads
     * everything and publishes nothing. Correct, not a misconfiguration.
     *
     * ⚠️ THE FIELD IS `$name` ON THE WAY IN AND `title` ON THE WAY BACK. The
     * write contract accepts `name`; `Project::$title` is what the same value is
     * called in every response, on both the read and the write. Sending `title`
     * in `$options` sets nothing and the server will refuse the call for a
     * missing `name`.
     *
     * `$description` is required — the column is NOT NULL upstream.
     *
     * NO `status` DEFAULT is applied. "No status yet" is a real state a project
     * can be in, and inventing `PLANNING` here would make every SDK-created
     * project look different from every form-created one.
     *
     * NO LOGO AND NO BANNER: both are uploads, which this surface does not have,
     * so `Project::$coverUrl` comes back null and there is no endpoint yet to
     * change it.
     *
     * @param array<string, mixed> $options `nameArabic` (defaults to `name`
     *                                      server-side), `status` (`PLANNING` |
     *                                      `IN_PROGRESS` | `ON_HOLD` |
     *                                      `COMPLETED` | `CANCELLED`),
     *                                      `startDate`, `endDate` (ISO 8601),
     *                                      `initialPrice`, `finalPrice`,
     *                                      `marketValue`, `currentAmount`,
     *                                      `projectLink`, `products`
     *                                      (`[{name, nameArabic?, price?, link?}]`).
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

        return Project::fromArray($this->requester->request('POST', 'sdk/projects', body: $body));
    }

    /**
     * Update a project.
     *
     * The key's owner may ALWAYS edit what it created — authorship is checked
     * FIRST, exactly as the web app checks it. Editing anyone else's needs
     * `projects:manage`, `projects:create`, `projects:moderate` or
     * `projects:edit`.
     *
     * `null` IS MEANINGFUL on the nullable fields — `status`, `startDate`,
     * `endDate`, the three prices and `projectLink` are genuinely cleared by it.
     * `name`, `nameArabic` and `description` are NOT NULL columns and a null
     * there is refused.
     *
     * @param array<string, mixed> $fields Only what you want changed; same names
     *                                     as create (`name`, not `title`). An
     *                                     empty array is a 400, not a no-op.
     *
     * @throws GurbApiException
     */
    public function update(string $projectId, array $fields): Project
    {
        return Project::fromArray($this->requester->request(
            'PATCH',
            // rawurlencode, so a slash inside a caller-supplied id cannot walk
            // the request onto a path this SDK never meant to call.
            'sdk/projects/' . \rawurlencode($projectId),
            body: $fields,
        ));
    }

    /**
     * Delete a project.
     *
     * Narrower than update and not in the way the other sections are:
     * `projects:create` drops but `projects:edit` STAYS. Authorship still
     * counts — the creator may delete their own. A HARD delete, and no
     * `project.deleted` webhook exists to announce it.
     *
     * @throws GurbApiException
     */
    public function delete(string $projectId): void
    {
        $this->requester->request('DELETE', 'sdk/projects/' . \rawurlencode($projectId));
    }
}
