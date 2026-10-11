<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Http\Controllers;

use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\LensRegistry;
use Illuminate\Contracts\View\View;

final class DashboardController
{
    public function __invoke(EditorLinks $links, LensRegistry $registry): View
    {
        $tabs = [];

        foreach ($registry->booted() as $lens) {
            $tabs[$lens->key()] = ($lens instanceof Presentable ? $lens->presentation()->tabView : null) ?? 'monocle::tabs.table';
        }

        return view('monocle::dashboard', [
            'tabs' => $tabs,
            'api' => route('monocle.api.captures'),
            'paths' => ['base' => base_path(), 'mappings' => $links->mappings()],
            'assets' => [
                'css' => route('monocle.asset', ['file' => 'monocle.css']).'?v='.AssetController::version('monocle.css'),
                'js' => route('monocle.asset', ['file' => 'monocle.js']).'?v='.AssetController::version('monocle.js'),
                'icon' => route('monocle.asset', ['file' => 'monocle.svg']).'?v='.AssetController::version('monocle.svg'),
            ],
        ]);
    }
}
