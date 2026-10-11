<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\ResponseCache;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class CachedResponseController
{
    use RendersResult;

    public function __invoke(): View
    {
        return $this->result('Cached response', 'Rendered at '.now()->toTimeString().'. Reload to get it from the cache.');
    }
}
