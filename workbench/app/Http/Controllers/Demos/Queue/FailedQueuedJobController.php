<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Queue;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Jobs\BrokenJob;

final class FailedQueuedJobController
{
    use RendersResult;

    public function __invoke(): View
    {
        BrokenJob::dispatch()->onConnection('database');

        return $this->result('Failing queued job', 'BrokenJob is on the database queue. Work the queue (/demo/queue/work); the job capture has the failure.');
    }
}
