<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Processes;

use Illuminate\Support\Facades\Process;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class RunProcessesController
{
    use RendersResult;

    public function __invoke(): View
    {
        $version = Process::run([PHP_BINARY, '-v']);
        $failing = Process::run([PHP_BINARY, '-r', 'fwrite(STDERR, "broken"); exit(3);']);

        return $this->result('Run processes', strtok($version->output(), "\n").' - and one that exited with '.$failing->exitCode().'.');
    }
}
