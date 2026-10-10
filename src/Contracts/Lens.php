<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts;

use ExeQue\Monocle\Data\Entry;
use ExeQue\Monocle\Recorder;
use Illuminate\Contracts\Foundation\Application;

interface Lens
{
    /**
     * Section key under Entry->sections and in the MCP `sections` argument.
     */
    public function key(): string;

    /**
     * One or two sentences for the agent: what this lens records and which
     * fields matter. Shown next to the section key in monocle-show.
     */
    public function description(): string;

    /**
     * Hook into the framework however the lens needs to. Called once per process.
     */
    public function boot(Application $app, Recorder $recorder): void;

    /**
     * Flush buffered data into the entry right before it is stored.
     */
    public function finalize(Entry $entry): void;

    /**
     * Forget per-entry state between entries in long-running processes.
     */
    public function reset(): void;
}
