<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * A task on a community's board.
 *
 * ONE CLASS FOR THE LIST AND THE DETAIL, because they describe one resource and
 * two classes would mean two names for the same thing. The detail carries five
 * fields the list cannot: the list query returns task rows, and a task row knows
 * only its heading — its section, the section's name and its dependency graph
 * are joins further up, which the platform does not perform for a list.
 *
 * So `$sectionId`, `$sectionName`, `$headingName`, `$dependsOn`, `$blocking` and
 * `$activityCount` are populated by `TasksResource::get()` and are null/empty on
 * every row from `TasksResource::list()`. That is a property of the endpoint you
 * called, NOT a fact about the task — an empty `$dependsOn` on a list row does
 * not mean the task has no prerequisites.
 *
 * TASKS ARE THREE LEVELS DEEP: section → heading → task. `$headingId` is the
 * container and it is required on create; there is no inbox to fall back on.
 */
final class Task
{
    private function __construct(
        public readonly string $id,
        /** The heading this task files under. Required on create; see TasksResource::create(). */
        public readonly string $headingId,
        public readonly string $title,
        public readonly ?string $shortDescription,
        public readonly ?string $description,
        public readonly ?string $status,
        public readonly ?string $priority,
        public readonly int $points,
        public readonly ?int $estimatedMinutes,
        /** @var list<string> */
        public readonly array $tags,
        /** ISO 8601, or null. The stored column is `dueDate`; the contract says `dueAt`. */
        public readonly ?string $dueAt,
        public readonly ?string $completedAt,
        /**
         * Whether the task appears on the board.
         *
         * `false` is the product's HIDE, not a delete: the row keeps its id and
         * stays addressable, it simply drops out of `list()`. Because `list()`
         * filters on it, every listed row is `true` and only `get()` can show
         * `false`.
         */
        public readonly bool $isActive,
        /**
         * Assign this task to everyone who joins the community FROM NOW ON.
         *
         * Never retroactive — flipping it touches nobody who is already a
         * member — and suppressed entirely when the parent section is restricted
         * to specific members, because a task inside a section someone cannot
         * see would be invisible work.
         */
        public readonly bool $autoAssignNewMembers,
        /**
         * How many people it is assigned to. A COUNT, never a list.
         *
         * The platform's task detail returns an empty assignments array by
         * design, so a count is the honest published fact. Zero on the response
         * to a create even when the heading's assignees were inherited: the
         * count is not re-queried on the write path. Read the task back if you
         * need it.
         */
        public readonly int $assignmentCount,
        /**
         * Who created it, or null.
         *
         * Null is common and not an error: the stored creator column has no
         * foreign key, so the person may have been deleted from the platform
         * entirely. A hollow object would be worse than an honest null.
         */
        public readonly ?Author $createdBy,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        /** Detail only — null on a list row. See the class note. */
        public readonly ?string $sectionId = null,
        /** Detail only — null on a list row. */
        public readonly ?string $sectionName = null,
        /** Detail only — null on a list row. */
        public readonly ?string $headingName = null,
        /**
         * Tasks this one waits for. Detail only — EMPTY on a list row, which
         * does not mean "no prerequisites".
         *
         * @var list<TaskLink>
         */
        public readonly array $dependsOn = [],
        /**
         * Tasks waiting for this one. Detail only — empty on a list row.
         *
         * @var list<TaskLink>
         */
        public readonly array $blocking = [],
        /** Rows in the task's activity log. Detail only; the entries themselves are not published. */
        public readonly int $activityCount = 0,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            headingId: Decode::str($data, 'headingId'),
            title: Decode::str($data, 'title'),
            shortDescription: Decode::nullableStr($data, 'shortDescription'),
            description: Decode::nullableStr($data, 'description'),
            status: Decode::nullableStr($data, 'status'),
            priority: Decode::nullableStr($data, 'priority'),
            points: Decode::int($data, 'points'),
            estimatedMinutes: \is_numeric($data['estimatedMinutes'] ?? null)
                ? (int) $data['estimatedMinutes']
                : null,
            tags: Decode::strList($data, 'tags'),
            dueAt: Decode::nullableStr($data, 'dueAt'),
            completedAt: Decode::nullableStr($data, 'completedAt'),
            // Absent reads as TRUE, matching the server, which publishes
            // `isActive !== false`. A task is on the board unless it says
            // otherwise.
            isActive: Decode::bool($data, 'isActive', true),
            autoAssignNewMembers: Decode::bool($data, 'autoAssignNewMembers'),
            assignmentCount: Decode::int($data, 'assignmentCount'),
            createdBy: \is_array($data['createdBy'] ?? null)
                ? Author::fromArray($data['createdBy'])
                : null,
            createdAt: Decode::nullableStr($data, 'createdAt'),
            updatedAt: Decode::nullableStr($data, 'updatedAt'),
            sectionId: Decode::nullableStr($data, 'sectionId'),
            sectionName: Decode::nullableStr($data, 'sectionName'),
            headingName: Decode::nullableStr($data, 'headingName'),
            dependsOn: \array_map(TaskLink::fromArray(...), Decode::rows($data, 'dependsOn')),
            blocking: \array_map(TaskLink::fromArray(...), Decode::rows($data, 'blocking')),
            activityCount: Decode::int($data, 'activityCount'),
        );
    }
}
