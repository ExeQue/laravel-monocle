<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\ResponseCache;

use Illuminate\View\View;
use Spatie\ResponseCache\Facades\ResponseCache;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class ClearCacheController
{
    use RendersResult;

    public function __invoke(): View
    {
        ResponseCache::clear();

        return $this->result('Clear the cache', 'The response cache was cleared.');
    }
}
