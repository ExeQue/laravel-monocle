<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Session;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class WriteAndFlashController
{
    use RendersResult;

    public function __invoke(Request $request): View
    {
        $request->session()->put('demo.last_visit', now()->toIso8601String());
        $request->session()->increment('demo.visits');
        $request->session()->flash('status', 'Flashed for the next request.');

        return $this->result('Session', $request->session()->get('demo'));
    }
}
