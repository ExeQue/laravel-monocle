<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Inertia;

use Illuminate\View\View;

final class SharedPropOnPartialController
{
    public function __invoke(): View
    {
        return view('fetch', [
            'title' => 'Shared prop on a partial reload',
            'description' => 'A partial reload of the Inertia demo that only leaves out "report". The shared "app" prop is sent although the reload did not ask for it.',
            'method' => 'GET',
            'url' => '/demo/inertia',
            'headers' => ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Dashboard', 'X-Inertia-Partial-Except' => 'report', 'Accept' => 'text/html, application/xhtml+xml'],
        ]);
    }
}
