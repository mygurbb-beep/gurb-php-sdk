<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * The result of a partially-successful batch.
 *
 * READ THIS BEFORE ASSUMING SUCCESS. `bulkUpsert()` does not throw when
 * individual rows fail — a 200 here means "the batch was processed", not "every
 * row landed". An empty `failed` is the only proof that everything landed:
 *
 *     $result = $gurb->members->bulkUpsert($rows);
 *     foreach ($result->failed as $failure) {
 *         $log->warning("row {$failure->index} ({$failure->externalUserId}): {$failure->message}");
 *     }
 *
 * `created` and `updated` are separate because they answer a question you will
 * eventually ask: re-running this as a nightly sync should produce a large
 * `updated` and a near-empty `created`, and if it does not, your
 * `externalUserId` is not as stable as you think and you are duplicating people.
 */
final class BulkMemberResult
{
    /**
     * @param list<Member>      $created Rows whose externalUserId was new here.
     * @param list<Member>      $updated Rows that matched an existing member.
     * @param list<BulkFailure> $failed  Rows the server refused, individually.
     * @param int               $total   Rows it received. created+updated+failed.
     */
    private function __construct(
        public readonly array $created,
        public readonly array $updated,
        public readonly array $failed,
        public readonly int $total,
    ) {
    }

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }

    /** Rows that landed, either way. */
    public function successCount(): int
    {
        return \count($this->created) + \count($this->updated);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $created = self::mapList($data, 'created', Member::fromArray(...));
        $updated = self::mapList($data, 'updated', Member::fromArray(...));
        $failed = self::mapList($data, 'failed', BulkFailure::fromArray(...));

        return new self(
            created: $created,
            updated: $updated,
            failed: $failed,
            // Falling back to the sum rather than 0 keeps `total` honest if the
            // server ever omits it: a zero total next to 500 created members
            // would read as "nothing happened" in a log.
            total: Decode::int($data, 'total', \count($created) + \count($updated) + \count($failed)),
        );
    }

    /**
     * @template T of object
     *
     * @param array<string, mixed>                 $data
     * @param callable(array<string, mixed>): T    $mapItem
     *
     * @return list<T>
     */
    private static function mapList(array $data, string $key, callable $mapItem): array
    {
        $raw = $data[$key] ?? null;
        if (!\is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $row) {
            if (\is_array($row)) {
                $items[] = $mapItem($row);
            }
        }

        return $items;
    }
}
