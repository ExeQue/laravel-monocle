<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A bus command that is not queueable: dispatchSync() runs it through the
 * bus pipeline directly, which is where the SyncDispatchPipe records it.
 */
final class PlainCommand
{
    use Dispatchable;

    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
    }
}
