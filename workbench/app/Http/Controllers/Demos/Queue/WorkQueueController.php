<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Queue;

use Illuminate\Support\Facades\Process;
use Illuminate\View\View;

use function Orchestra\Testbench\package_path;

use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class WorkQueueController
{
    use RendersResult;

    public function __invoke(): View
    {
        // A separate process, like a real worker: each job it runs is a capture of its own.
        $run = Process::path(package_path())->timeout(60)->run([PHP_BINARY, 'vendor/bin/testbench', 'queue:work', 'database', '--stop-when-empty']);

        return $this->result('Work the queue', trim($run->output().$run->errorOutput()) ?: 'The queue was empty.');
    }
}
