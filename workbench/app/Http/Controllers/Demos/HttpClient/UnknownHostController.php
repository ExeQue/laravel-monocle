<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\HttpClient;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class UnknownHostController
{
    use RendersResult;

    public function __invoke(): View
    {
        try {
            Http::connectTimeout(2)->get('https://payments.monocle-demo.invalid/health');
        } catch (ConnectionException $exception) {
            return $this->result('Failed connection', $exception->getMessage());
        }

        return $this->result('Failed connection', 'The host answered.');
    }
}
