<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts;

use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;

/**
 * The minimum a lens provides. Opt into more lifecycle through the companion
 * contracts: Configurable, Finalizable, Resettable, Summarizable.
 */
interface Lens
{
    /**
     * Section key under Entry->sections and in the MCP `sections` argument.
     */
    public function key(): string;

    /**
     * One or two sentences for the agent: what this lens records and which
     * fields matter. Shown in the MCP server instructions and in monocle-show.
     */
    public function description(): string;

    /**
     * Hook into the framework however the lens needs to. Called once per
     * process, only when Monocle and the lens are enabled.
     */
    public function boot(Application $app, Eyepiece $eyepiece): void;
}
