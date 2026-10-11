<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts;

/**
 * The minimum a lens provides. A lens hooks into the framework in a boot()
 * method, called once per process when Monocle and the lens are enabled; its
 * parameters are resolved from the container, as are the constructor's. Opt
 * into more lifecycle through the companion contracts: Advises, Configurable,
 * Finalizable, Resettable, Summarizable.
 */
interface Lens
{
    /**
     * Section key under Capture->sections and in the MCP `sections` argument.
     */
    public function key(): string;

    /**
     * One or two sentences for the agent: what this lens records and which
     * fields matter. Shown in the MCP server instructions and in monocle-show.
     */
    public function description(): string;
}
