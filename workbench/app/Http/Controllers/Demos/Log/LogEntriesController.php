<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Log;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class LogEntriesController
{
    use RendersResult;

    public function __invoke(): View
    {
        Log::info('A demo info entry.', ['user' => Auth::id()]);
        Log::warning('A demo warning entry.');
        Log::error('A demo error entry.', ['reason' => 'just testing']);

        return $this->result('Log', 'Wrote three log entries.');
    }
}
