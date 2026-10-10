<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts\Lenses;

use ExeQue\Monocle\Data\Capture;

interface Finalizable
{
    /**
     * Called right before the capture is stored: flush buffered state, derive
     * spans, close what is still open.
     */
    public function finalize(Capture $capture): void;
}
