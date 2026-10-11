<?php

declare(strict_types=1);

namespace Workbench\App\Livewire;

use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Throws while getting ready to render and handles it itself, so the page
 * still renders.
 */
final class BrokenWidget extends Component
{
    public ?string $problem = null;

    public function rendering(): void
    {
        throw new RuntimeException('The weather service did not answer.');
    }

    public function exception(Throwable $e, callable $stopPropagation): void
    {
        $this->problem = $e->getMessage();
        $stopPropagation();
    }

    public function render(): string
    {
        return <<<'BLADE'
            <p>The widget failed to load: {{ $problem }}</p>
            BLADE;
    }
}
