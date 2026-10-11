<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Monocle;

use ExeQue\Monocle\Facades\Monocle;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class OpenSpanController
{
    use RendersResult;

    public function __invoke(): View
    {
        Monocle::span('warm search index')->start();

        return $this->result('Span never stopped', 'A span was started and never stopped.');
    }
}
