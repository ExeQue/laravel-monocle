<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Http\Controllers;

use Composer\InstalledVersions;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\LensRegistry;
use Illuminate\Contracts\View\View;

/** @internal */
final class DashboardController
{
    public function __invoke(LensRegistry $registry): View
    {
        $tabs = [];

        foreach ($registry->booted() as $lens) {
            $tabView = $lens instanceof Presentable ? $lens->presentation()->tabView : null;
            $tabs[$lens->key()] = $tabView ?? 'monocle::tabs.table';
        }

        $labels = array_filter(array_map(fn (Presentation $presentation): ?string => $presentation->label, $registry->presentations()));

        return view('monocle::dashboard', [
            'tabs' => $tabs,
            // Section names as the tabs show them, where a lens gives one; the dashboard humanizes the rest.
            'labels' => $labels,
            'api' => route('monocle.api.captures'),
            // The project's root, which holds the application even when base_path() lives deeper (Testbench).
            'paths' => ['base' => realpath(InstalledVersions::getRootPackage()['install_path']) ?: base_path()],
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
