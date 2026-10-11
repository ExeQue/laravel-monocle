<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\HttpClient;

use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class FakedApiController
{
    use RendersResult;

    public function __invoke(): View
    {
        Http::fake([
            'api.example.test/users/*' => Http::response(['name' => 'Ada'], 200),
            'api.example.test/broken' => Http::response(['error' => 'Server exploded'], 500),
        ]);

        return $this->result('HTTP client', [
            'user' => Http::get('https://api.example.test/users/1')->json(),
            'broken' => Http::get('https://api.example.test/broken')->status(),
        ]);
    }
}
