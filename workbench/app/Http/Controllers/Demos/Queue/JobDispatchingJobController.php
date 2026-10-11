<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Queue;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Jobs\ImportBatch;

final class JobDispatchingJobController
{
    use RendersResult;

    public function __invoke(): View
    {
        ImportBatch::dispatch();

        return $this->result('Job dispatching a job', 'ImportBatch dispatched ProcessReport.');
    }
}
