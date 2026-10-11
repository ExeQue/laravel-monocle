<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Queue;

use Illuminate\View\View;
use RuntimeException;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Jobs\BrokenJob;

final class FailingJobController
{
    use RendersResult;

    public function __invoke(): View
    {
        try {
            BrokenJob::dispatch();
        } catch (RuntimeException $exception) {
            return $this->result('Failing job', $exception->getMessage());
        }

        return $this->result('Failing job', 'The job did not fail.');
    }
}
