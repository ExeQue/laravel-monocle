<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessReport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $report,
    ) {
    }

    public function handle(): void
    {
        usleep(20_000);
    }
}
