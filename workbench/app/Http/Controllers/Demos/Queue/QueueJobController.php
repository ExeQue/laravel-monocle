<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Queue;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Jobs\ProcessReport;

final class QueueJobController
{
    use RendersResult;

    public function __invoke(): View
    {
        ProcessReport::dispatch('monthly-sales')->onConnection('database');

        return $this->result('Queue a job', 'ProcessReport is waiting on the database queue. Work the queue to run it as a capture of its own.');
    }
}
