<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class HitsAndMissesController
{
    use RendersResult;

    public function __invoke(): View
    {
        $missed = Cache::get('demo:missing');
        Cache::put('demo:greeting', 'hello', 60);
        $hit = Cache::get('demo:greeting');
        $settings = Cache::remember('demo:settings', 60, fn (): array => ['theme' => 'dark']);

        foreach (range(1, 8) as $ignored) {
            Cache::get('demo:settings');
        }

        return $this->result('Cache', compact('missed', 'hit', 'settings'));
    }
}
