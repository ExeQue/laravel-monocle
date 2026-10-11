<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class FailedCommandController
{
    use RendersResult;

    public function __invoke(): View
    {
        $exitCode = Artisan::call('demo:fail');

        return $this->result('Failed command', "demo:fail exited with {$exitCode}.");
    }
}
