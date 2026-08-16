<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * One edge of a task's dependency graph.
 *
 * The same shape describes both directions — a task this one waits for
 * (`Task::$dependsOn`) and a task waiting for this one (`Task::$blocking`) — so
 * `taskId` always names the OTHER task, never the one you asked about.
 */
final class TaskLink
{
    private function __construct(
        public readonly string $taskId,
        public readonly string $title,
        public readonly ?string $status,
        public readonly ?string $priority,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            taskId: Decode::str($data, 'taskId'),
            title: Decode::str($data, 'title'),
            status: Decode::nullableStr($data, 'status'),
            priority: Decode::nullableStr($data, 'priority'),
        );
    }
}
