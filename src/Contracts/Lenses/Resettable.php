<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts\Lenses;

interface Resettable
{
    /**
     * Called after every entry: forget per-entry state so long-running
     * processes (queue workers, Octane) start the next entry clean.
     */
    public function reset(): void;
}
