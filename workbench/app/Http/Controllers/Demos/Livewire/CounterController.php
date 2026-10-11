<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Livewire;

use Illuminate\View\View;

final class CounterController
{
    public function __invoke(): View
    {
        return view('livewire-demo');
    }
}
