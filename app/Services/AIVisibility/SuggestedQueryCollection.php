<?php

namespace App\Services\AIVisibility;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int,SuggestedQuery>
 */
class SuggestedQueryCollection implements Countable, IteratorAggregate
{
    /**
     * @param array<int,SuggestedQuery> $queries
     */
    public function __construct(private readonly array $queries)
    {
    }

    /**
     * @return Traversable<int,SuggestedQuery>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->queries);
    }

    public function count(): int
    {
        return count($this->queries);
    }

    /**
     * @return array<int,SuggestedQuery>
     */
    public function all(): array
    {
        return $this->queries;
    }

    public function find(string $key): ?SuggestedQuery
    {
        foreach ($this->queries as $query) {
            if ($query->key === $key) {
                return $query;
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (SuggestedQuery $query): array => $query->toArray(), $this->queries);
    }
}
