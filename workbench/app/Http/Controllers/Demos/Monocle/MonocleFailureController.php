<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Monocle;

use Illuminate\View\View;
use Workbench\App\Events\ReportExported;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class MonocleFailureController
{
    use RendersResult;

    public function __invoke(): View
    {
        ReportExported::dispatch();

        return $this->result('Monocle failure', 'ReportExported was dispatched; reading its payload threw inside the events lens.');
    }
}
