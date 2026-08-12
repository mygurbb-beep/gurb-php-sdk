<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;
use IteratorAggregate;
use Traversable;

/**
 * One page of results.
 *
 * PHP has no generics, so the item type lives in a `@template` annotation that
 * static analysers (PHPStan, Psalm) understand and the runtime ignores. The
 * resource methods carry `@return Paginated<Tweet>` and similar, which is what
 * makes `foreach ($page as $tweet)` autocomplete in an IDE.
 *
 * @template T of object
 *
 * @implements IteratorAggregate<int, T>
 */
final class Paginated implements IteratorAggregate, \Countable
{
    /** @param list<T> $items */
    private function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $limit,
        public readonly int $total,
        public readonly bool $hasMore,
    ) {
    }

    /**
     * @template TItem of object
     *
     * @param array<string, mixed>            $data
     * @param callable(array<string, mixed>): TItem $mapItem
     *
     * @return self<TItem>
     */
    public static function fromArray(array $data, callable $mapItem): self
    {
        $rawItems = $data['items'] ?? [];
        $items = [];
        if (\is_array($rawItems)) {
            foreach ($rawItems as $raw) {
                if (\is_array($raw)) {
                    $items[] = $mapItem($raw);
                }
            }
        }

        $page = Decode::int($data, 'page', 1);
        $limit = Decode::int($data, 'limit', \count($items));
        $total = Decode::int($data, 'total', \count($items));

        return new self(
            items: $items,
            page: $page,
            limit: $limit,
            total: $total,
            // Derived only when the field is absent. Trusting the server's own
            // answer matters for feeds where `total` is an estimate or omitted
            // for cost reasons — arithmetic on a fuzzy total would end pagination
            // one page early.
            hasMore: \is_bool($data['hasMore'] ?? null)
                ? $data['hasMore']
                : ($page * $limit) < $total,
        );
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->items);
    }

    /** Items on THIS page, not the total. Use `->total` for that. */
    public function count(): int
    {
        return \count($this->items);
    }
}
