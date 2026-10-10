<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use Livewire\Component;
use RuntimeException;

final class Counter extends Component
{
    public int $count = 0;

    public function increment(int $by = 1): void
    {
        $this->count += $by;
    }

    public function explode(): void
    {
        throw new RuntimeException('component exploded');
    }

    public function render(): string
    {
        return '<div>Count: {{ $count }}</div>';
    }
}
