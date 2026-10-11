<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Middleware;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class SlowMiddlewareController
{
    use RendersResult;

    public function __invoke(): View
    {
        return $this->result('Slow middleware', 'Passed through SlowMiddleware.');
    }
}
