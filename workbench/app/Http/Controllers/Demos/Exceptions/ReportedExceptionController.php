<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Exceptions;

use Illuminate\View\View;
use RuntimeException;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class ReportedExceptionController
{
    use RendersResult;

    public function __invoke(): View
    {
        report(new RuntimeException('Reported but handled.'));

        return $this->result('Reported exception', 'The exception was reported and the request carried on.');
    }
}
