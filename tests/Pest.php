<?php

declare(strict_types=1);

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Reader\CaptureReader;

pest()->extend(Tests\TestCase::class)->in('Feature', 'Unit');

/**
 * The most recently stored capture, loaded from the configured store.
 */
function latestCapture(): ?Capture
{
    return app(CaptureReader::class)->find('latest');
}
