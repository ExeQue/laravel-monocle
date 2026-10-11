<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Filesystem;

use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class FailedReadController
{
    use RendersResult;

    public function __invoke(): View
    {
        return $this->result('Failed read', [
            'contents' => Storage::disk('demo')->get('reports/missing.pdf'),
        ]);
    }
}
