<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Processes;

use Illuminate\Support\Facades\Process;
use Illuminate\View\View;
use RuntimeException;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class ProcessTimeoutController
{
    use RendersResult;

    public function __invoke(): View
    {
        try {
            Process::timeout(1)->run([PHP_BINARY, '-r', 'sleep(3);']);
        } catch (RuntimeException $exception) {
            return $this->result('Process timeout', $exception->getMessage());
        }

        return $this->result('Process timeout', 'The process finished in time.');
    }
}
