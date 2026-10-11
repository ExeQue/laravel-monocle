<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\HttpClient;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class FailedConnectionsController
{
    use RendersResult;

    public function __invoke(): View
    {
        $outcomes = [];

        foreach ([
            'expired certificate' => 'https://expired.badssl.com/',
            'self-signed certificate' => 'https://self-signed.badssl.com/',
            'unknown host' => 'https://monocle-demo.invalid/',
            'no answer' => 'http://10.255.255.1/',
        ] as $label => $url) {
            try {
                $outcomes[$label] = Http::connectTimeout(2)->timeout(3)->get($url)->status();
            } catch (Throwable $exception) {
                $outcomes[$label] = class_basename($exception).': '.Str::limit($exception->getMessage(), 90);
            }
        }

        return $this->result('Failed connections', $outcomes);
    }
}
