<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One keyset page: the rows, and the opaque cursor that fetches the next ones.
 *
 * @template T of object
 */
final readonly class SearchPage
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }

    public function hasMore(): bool
    {
        return null !== $this->nextCursor;
    }
}
