<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Http\Controllers;

use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\LensRegistry;
use Illuminate\Contracts\View\View;

/** @internal */
final class DashboardController
{
    public function __invoke(EditorLinks $links, LensRegistry $registry): View
    {
        $tabs = [];

        foreach ($registry->booted() as $lens) {
            $tabView = $lens instanceof Presentable ? $lens->presentation()->tabView : null;
            $tabs[$lens->key()] = $tabView ?? 'monocle::tabs.table';
        }

        return view('monocle::dashboard', [
            'tabs' => $tabs,
            'api' => route('monocle.api.captures'),
            'paths' => ['base' => base_path(), 'mappings' => $links->mappings()],
            'assets' => [
                'css' => $this->assetUrl('monocle.css'),
                'js' => $this->assetUrl('monocle.js'),
                'icon' => $this->assetUrl('monocle.svg'),
            ],
        ]);
    }

    /**
     * Versioned by content, so browsers cache an asset until the package updates it.
     */
    private function assetUrl(string $file): string
    {
        return route('monocle.asset', ['file' => $file]).'?v='.AssetController::version($file);
    }
}
