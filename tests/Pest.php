<?php

declare(strict_types=1);

if (! class_exists(Laravel\Pennant\Events\FeatureRetrieved::class)) {
    require __DIR__.'/Fixtures/Stubs/PennantEvents.php';
}

if (! class_exists(Spatie\ResponseCache\Events\ResponseCacheHitEvent::class)) {
    require __DIR__.'/Fixtures/Stubs/ResponseCacheEvents.php';
}

if (! class_exists(Laravel\Folio\Events\ViewMatched::class)) {
    require __DIR__.'/Fixtures/Stubs/FolioViewMatched.php';
}

if (! class_exists(Laravel\Octane\Events\RequestReceived::class)) {
    require __DIR__.'/Fixtures/Stubs/OctaneRequestReceived.php';
}

if (! class_exists(Laravel\Sanctum\Events\TokenAuthenticated::class)) {
    require __DIR__.'/Fixtures/Stubs/SanctumTokenAuthenticated.php';
}

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Reader\CaptureReader;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * The most recently stored capture, loaded from the configured store.
 */
function latestCapture(): ?Capture
{
    return app(CaptureReader::class)->find('latest');
}
