<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Paginated;
use Gurb\Model\Task;

/**
 * The community's task board.
 *
 * TASKS ARE THREE LEVELS DEEP — section → heading → task — and only the last of
 * those is on this surface. `headingId` is required on create and there is no
 * inbox to fall back on; read the ids off the community's own board.
 *
 * ASSIGNMENT IS NOT PUBLISHED IN v1, and it is worth knowing exactly what that
 * costs before you design around it.
 *
 * WHAT YOU LOSE: you cannot choose who does a task through this API, and you
 * cannot read back who is on one — only how many, `Task::$assignmentCount`.
 * There is no assign endpoint and no unassign endpoint, deliberately: shipping
 * assign without unassign would leave an integration unable to undo its own
 * writes, and the internal assign call has a `role: 'MEMBER'` mode that expands
 * to EVERY approved member of the community in one write and notifies each of
 * them — one mistyped request, a push to the whole community. That needs its own
 * endpoint and its own documentation, not a value in an enum.
 *
 * WHAT STILL WORKS, and it is more than it sounds: assignment by INHERITANCE.
 * Creating a task copies every assignee the chosen heading and its section
 * already carry. So **choosing `headingId` IS the assignment decision** in a
 * community that assigns people to headings — which is how the feature is
 * designed to be used — and such a community gets correctly-assigned tasks from
 * this SDK today. `autoAssignNewMembers` also keeps working, for future joiners.
 */
final class TasksResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * List tasks.
     *
     * NO PERMISSION IS NEEDED TO READ THE BOARD — Gurb's own route has no
     * permission guard on either read, so any approved member may look. Only
     * changing it needs `tasks:manage`. That asymmetry is the product's and this
     * SDK inherits it rather than tightening it.
     *
     * The `tasks` feature IS required, on reads as much as writes: a community
     * whose plan lacks it answers 403 FEATURE_NOT_AVAILABLE here. That is an
     * entitlement, not a permission, and no grant will fix it.
     *
     * Listed rows are LIST rows: `sectionId`, `sectionName`, `headingName`,
     * `dependsOn`, `blocking` and `activityCount` are null/empty on every one of
     * them. That is a property of the endpoint, not of the task — use `get()`.
     * `list()` also filters to active tasks, so every row here has
     * `isActive === true`.
     *
     * @param list<string> $status   `PENDING` | `IN_PROGRESS` | `COMPLETED` |
     *                               `OVERDUE`. An unrecognised value is a 400,
     *                               not a silently-dropped filter — a dropped
     *                               filter returns MORE rows than you asked for,
     *                               which is the failure you do not notice.
     * @param list<string> $priority `HIGH` | `MEDIUM` | `LOW`.
     * @param string|null  $assigneeUserId A **User.id**, not a member id and not
     *                                     a group id. The column behind it holds
     *                                     all three depending on the assignment
     *                                     kind, which is why this parameter is
     *                                     named for the one it means.
     * @param string|null  $dueBefore ISO 8601.
     * @param string|null  $dueAfter  ISO 8601.
     *
     * @return Paginated<Task>
     *
     * @throws GurbApiException
     */
    public function list(
        ?int $page = null,
        ?int $limit = null,
        array $status = [],
        array $priority = [],
        ?string $assigneeUserId = null,
        ?string $headingId = null,
        ?string $sectionId = null,
        ?string $dueBefore = null,
        ?string $dueAfter = null,
        ?string $search = null,
    ): Paginated {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/tasks', [
                'page' => $page,
                'limit' => $limit,
                // Comma-joined rather than repeated, because the server accepts
                // both spellings and one string keeps `Requester::request`'s
                // scalar query contract intact. An empty list becomes null and
                // is dropped from the URL entirely — an empty `?status=` would
                // be a filter matching nothing.
                'status' => $status === [] ? null : \implode(',', $status),
                'priority' => $priority === [] ? null : \implode(',', $priority),
                'assigneeUserId' => $assigneeUserId,
                'headingId' => $headingId,
                'sectionId' => $sectionId,
                'dueBefore' => $dueBefore,
                'dueAfter' => $dueAfter,
                'search' => $search,
            ]),
            Task::fromArray(...),
        );
    }

    /**
     * One task, with the five fields a list row cannot carry: its section, that
     * section's name, its heading's name, its dependency graph and its activity
     * count.
     *
     * No permission needed — see `list()`. Feature-gated the same way.
     *
     * This is also the ONLY way to see a hidden task (`isActive === false`),
     * which stays addressable by id after it drops off the board.
     *
     * @throws GurbApiException
     */
    public function get(string $taskId): Task
    {
        return Task::fromArray(
            // rawurlencode, so a slash inside a caller-supplied id cannot walk
            // the request onto a path this SDK never meant to call.
            $this->requester->request('GET', 'sdk/tasks/' . \rawurlencode($taskId)),
        );
    }

    /**
     * Create a task.
     *
     * `tasks:manage` — the ONLY permission the tasks module has, and it is
     * required for create, update AND delete with no authorship shortcut. A
     * task's creator gets no special standing, on this surface or in the web
     * app. Requirements differ per section across this SDK because it mirrors
     * Gurb exactly rather than unifying them, and a plain member holds none of
     * them: `MEMBER` is an empty permission set in Gurb, so a key whose owner
     * was granted nothing reads the whole board and changes nothing. That is
     * correct, not a misconfiguration.
     *
     * Feature-gated on `tasks`: without it every call here 403s with
     * FEATURE_NOT_AVAILABLE, reads included. An entitlement, not a permission.
     *
     * `$headingId` DECIDES WHO DOES THE WORK — the new task inherits every
     * assignee of that heading and its section. See the class note.
     *
     * `$title` IS CAPPED AT 40 CHARACTERS upstream. That is genuinely
     * surprising and is not a typo; an integrator will hit it. The cap is left
     * to the server so this SDK cannot drift from it.
     *
     * `status` is NOT accepted on create — the insert hardcodes `PENDING`. Send
     * a `PATCH` immediately after if you need a task that starts in progress.
     *
     * @param array<string, mixed> $options `shortDescription`, `description`,
     *                                      `dueAt` (ISO 8601), `priority`
     *                                      (`HIGH` | `MEDIUM` | `LOW`, default
     *                                      `MEDIUM`), `points`,
     *                                      `estimatedMinutes`, `tags`,
     *                                      `autoAssignNewMembers`.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) with NO request made
     *                          when headingId or title is blank.
     */
    public function create(string $headingId, string $title, array $options = []): Task
    {
        $trimmedHeadingId = \trim($headingId);
        $trimmedTitle = \trim($title);

        if ($trimmedHeadingId === '' || $trimmedTitle === '') {
            throw GurbApiException::localValidation('A task needs a headingId and a title.');
        }

        // The trimmed values are what get SENT, not merely what got validated.
        $body = \array_merge($options, [
            'headingId' => $trimmedHeadingId,
            'title' => $trimmedTitle,
        ]);

        return Task::fromArray($this->requester->request('POST', 'sdk/tasks', body: $body));
    }

    /**
     * Update a task. `tasks:manage`, same as create — no authorship shortcut.
     *
     * THREE FIELDS HAVE CONSEQUENCES THEIR NAMES DO NOT ADVERTISE:
     *
     *   - `status: 'COMPLETED'` stamps a completion time and announces the
     *     completion — but only on the TRANSITION. Re-sending COMPLETED on an
     *     already-completed task is a plain update, and moving away from it is a
     *     reopen. Exactly one announcement leaves per call, so an integration
     *     mirroring state can rely on it.
     *   - `autoAssignNewMembers: true` assigns this task to everyone approved
     *     into the community FROM NOW ON. Never retroactive, and suppressed
     *     entirely when the parent section is restricted to specific members.
     *   - `isActive: false` is the product's HIDE, not a delete: the task drops
     *     out of `list()` and keeps its id. Nothing is announced.
     *
     * `shortDescription: null` and `dueAt: null` clear those fields.
     *
     * @param array<string, mixed> $fields Only what you want changed: `title`,
     *                                     `shortDescription`, `description`,
     *                                     `dueAt`, `priority`, `status`,
     *                                     `points`, `estimatedMinutes`, `tags`,
     *                                     `isActive`, `autoAssignNewMembers`.
     *                                     An empty array is a 400, not a no-op.
     *
     * @throws GurbApiException
     */
    public function update(string $taskId, array $fields): Task
    {
        return Task::fromArray($this->requester->request(
            'PATCH',
            // rawurlencode, so a slash inside a caller-supplied id cannot walk
            // the request onto a path this SDK never meant to call.
            'sdk/tasks/' . \rawurlencode($taskId),
            body: $fields,
        ));
    }

    /**
     * Delete a task. `tasks:manage`.
     *
     * A HARD delete, unlike a blog on this same surface: the row and everything
     * cascading from it — assignments, dependencies, the activity log — are
     * gone. There is no `deletedAt` and no undo. If you want it off the board
     * but recoverable, `update($id, ['isActive' => false])` is the operation you
     * actually want.
     *
     * @throws GurbApiException
     */
    public function delete(string $taskId): void
    {
        $this->requester->request('DELETE', 'sdk/tasks/' . \rawurlencode($taskId));
    }
}
