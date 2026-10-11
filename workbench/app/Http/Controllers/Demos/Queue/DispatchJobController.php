<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Queue;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Jobs\ProcessReport;

final class DispatchJobController
{
    use RendersResult;

    public function __invoke(): View
    {
        ProcessReport::dispatch('monthly-sales');

        return $this->result('Dispatch a job', 'ProcessReport ran on the sync queue.');
    }
}
