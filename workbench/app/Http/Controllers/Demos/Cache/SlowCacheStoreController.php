<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class SlowCacheStoreController
{
    use RendersResult;

    public function __invoke(): View
    {
        $rates = Cache::store('slow')->remember('demo:exchange-rates', 60, fn (): array => ['EUR' => 7.46, 'USD' => 6.89]);

        return $this->result('Slow cache', $rates);
    }
}
