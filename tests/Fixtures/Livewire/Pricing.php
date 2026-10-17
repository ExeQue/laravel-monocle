<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.page')]
final class Pricing extends Component
{
    public function render(): View
    {
        return view('livewire.pricing');
    }
}
