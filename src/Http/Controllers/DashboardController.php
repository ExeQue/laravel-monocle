<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Http\Controllers;

use Illuminate\Contracts\View\View;

final class DashboardController
{
    public function __invoke(): View
    {
        return view('monocle::dashboard', [
            'api' => route('monocle.api.captures'),
            'assets' => [
                'css' => route('monocle.asset', ['file' => 'monocle.css']).'?v='.AssetController::version('monocle.css'),
                'js' => route('monocle.asset', ['file' => 'monocle.js']).'?v='.AssetController::version('monocle.js'),
                'icon' => route('monocle.asset', ['file' => 'monocle.svg']).'?v='.AssetController::version('monocle.svg'),
            ],
        ]);
    }
}
