<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Livewire;

use Illuminate\View\View;

final class ComponentExceptionController
{
    public function __invoke(): View
    {
        return view('alarms.livewire');
    }
}
