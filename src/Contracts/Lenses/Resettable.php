<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts\Lenses;

interface Resettable
{
    /**
     * Called after every capture: forget per-capture state so long-running
     * processes (queue workers, Octane) start the next capture clean.
     */
    public function reset(): void;
}
