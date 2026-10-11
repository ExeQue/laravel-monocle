<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts\Lenses;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;

interface Summarizable
{
    /**
     * Contribute totals and warnings to the capture summary.
     */
    public function summarize(Capture $capture): Summary;
}
