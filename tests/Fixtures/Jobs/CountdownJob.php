<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Counts down by one per run and queues itself again until it reaches zero.
 */
final class CountdownJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $remaining,
    ) {
    }

    public function handle(): void
    {
        if ($this->remaining === 0) {
            return;
        }

        self::dispatch($this->remaining - 1)->onConnection('database');
    }
}
