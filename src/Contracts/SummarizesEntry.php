<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts;

use ExeQue\Monocle\Data\Entry;

interface SummarizesEntry
{
    /**
     * Contribute totals and warnings to the entry summary.
     *
     * @return array{totals?: array<string, mixed>, warnings?: list<string>}
     */
    public function summarize(Entry $entry): array;
}
