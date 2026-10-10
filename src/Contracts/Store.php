<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts;

use ExeQue\Monocle\Data\Capture;

interface Store
{
    /**
     * Persist the capture and trim the ring buffer to the configured limit.
     */
    public function put(Capture $capture): void;

    public function find(string $id): ?Capture;

    /**
     * Summary rows for every stored capture, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function index(): array;

    public function clear(): void;
}
