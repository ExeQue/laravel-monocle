<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts;

use ExeQue\Monocle\Data\Entry;

interface Store
{
    /**
     * Persist the entry and trim the ring buffer to the configured limit.
     */
    public function put(Entry $entry): void;

    public function find(string $id): ?Entry;

    /**
     * Summary rows for every stored entry, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function index(): array;

    public function clear(): void;
}
