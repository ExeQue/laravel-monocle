<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

final class RecordingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static int $handled = 0;

    public function __construct(public readonly string $label = 'job')
    {
    }

    public function handle(): void
    {
        self::$handled++;

        Cache::put('job:' . $this->label, true, 60);
    }
}
