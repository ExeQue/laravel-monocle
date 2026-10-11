<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Counts down by one per run and queues itself again until it reaches zero,
 * for a trace several jobs deep.
 */
final class Countdown implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $remaining,
    ) {
    }

    public function handle(): void
    {
        Log::info("Countdown at {$this->remaining}.");

        if ($this->remaining === 0) {
            return;
        }

        self::dispatch($this->remaining - 1)->onConnection('database');
    }
}
