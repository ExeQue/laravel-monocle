<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Commands;

use Illuminate\Support\Facades\Process;
use Illuminate\View\View;

use function Orchestra\Testbench\package_path;

use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class FailedScheduledTaskController
{
    use RendersResult;

    public function __invoke(): View
    {
        // Like the queue worker demo: schedule:run is a command capture of its own.
        $run = Process::path(package_path())->timeout(60)->run([PHP_BINARY, 'vendor/bin/testbench', 'schedule:run']);

        return $this->result('Failed scheduled task', trim($run->output().$run->errorOutput()));
    }
}
