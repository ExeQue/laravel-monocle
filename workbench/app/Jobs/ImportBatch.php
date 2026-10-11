<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ImportBatch implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        ProcessReport::dispatch('imported-batch');
    }
}
