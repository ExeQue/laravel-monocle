<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Processes;

use Illuminate\Support\Facades\Process;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class SlowProcessController
{
    use RendersResult;

    public function __invoke(): View
    {
        Process::run([PHP_BINARY, '-r', 'usleep(1_100_000);']);

        return $this->result('Slow process', 'A process that took 1.1 seconds.');
    }
}
