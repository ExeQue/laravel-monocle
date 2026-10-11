<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Concerns;

use Illuminate\View\View;

trait RendersResult
{
    private function result(string $title, mixed $output = null): View
    {
        return view('result', ['title' => $title, 'output' => $output]);
    }
}
