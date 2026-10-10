<?php

declare(strict_types=1);

use ExeQue\Monocle\Data\Entry;
use ExeQue\Monocle\Reader\EntryReader;

pest()->extend(Tests\TestCase::class)->in('Feature', 'Unit');

/**
 * The most recently stored entry, loaded from the configured store.
 */
function latestEntry(): ?Entry
{
    return app(EntryReader::class)->find('latest');
}
