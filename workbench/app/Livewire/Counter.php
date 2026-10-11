<?php

declare(strict_types=1);

namespace Workbench\App\Livewire;

use Livewire\Component;

final class Counter extends Component
{
    public int $count = 0;

    public string $label = 'Clicks';

    public function increment(int $by = 1): void
    {
        $this->count += $by;
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div style="display:flex;gap:.5rem;align-items:center">
                <input wire:model.live="label" style="width:8rem">
                <strong>{{ $label }}: {{ $count }}</strong>
                <button wire:click="increment">+1</button>
                <button wire:click="increment(5)">+5</button>
            </div>
            BLADE;
    }
}
