<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts\Lenses;

use ExeQue\Monocle\Data\Entry;

interface Finalizable
{
    /**
     * Called right before the entry is stored: flush buffered state, derive
     * spans, close what is still open.
     */
    public function finalize(Entry $entry): void;
}
