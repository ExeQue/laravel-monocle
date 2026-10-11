<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;

/**
 * Tags every capture and adds an array total with an empty item, for the
 * console summary rendering.
 */
final class ConsoleSummaryLens implements Lens, Summarizable
{
    public function key(): string
    {
        return 'console_summary';
    }

    public function description(): string
    {
        return 'Tags captures for the console tests.';
    }

    public function summarize(Capture $capture): Summary
    {
        return Summary::make()
            ->tag('beta')
            ->total('console_summary_cache', ['used' => 2, 'unused' => 0], 'cache');
    }
}
