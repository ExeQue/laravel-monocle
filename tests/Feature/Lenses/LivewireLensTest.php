<?php

declare(strict_types=1);

use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\LivewireLens;
use Livewire\Livewire;
use Tests\Fixtures\Livewire\Counter;

covers(LivewireLens::class);

describe(LivewireLens::class, function (): void {
    it('records mounted components with their render', function (): void {
        Livewire::test(Counter::class)->assertSee('Count: 0');

        $capture = latestCapture();
        $rows = $capture->rows('livewire');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['class'])->toBe(Counter::class)
            ->and($rows[0]['lifecycle'])->toBe('mount')
            ->and($rows[0]['render_ms'])->toBeFloat()
            ->and($rows[0]['html_bytes'])->toBeGreaterThan(0)
            ->and($rows[0]['calls'])->toBe([])
            ->and($capture->spansOfKind('livewire'))->toHaveCount(1)
            ->and($capture->spansOfKind('livewire')[0]->isOpen())->toBeFalse();
    });

    it('records calls, updates and exceptions on subsequent requests', function (): void {
        $component = Livewire::test(Counter::class);

        $component->set('count', 5)->call('increment', 2)->assertSet('count', 7);

        $updated = latestCapture();
        $calls = collect($updated->rows('livewire'))->flatMap(fn (array $row): array => $row['calls']);

        expect($updated->rows('livewire')[0]['lifecycle'])->toBe('update')
            ->and($calls->pluck('method')->all())->toContain('increment')
            ->and($calls->firstWhere('method', 'increment')['params'])->toBe([2])
            ->and($calls->firstWhere('method', 'increment')['duration'])->toBeNumeric();

        try {
            $component->call('explode');
        } catch (RuntimeException) {
        }

        // The exception escaped before the kernel terminated, so the capture is still open.
        $exploded = app(Eyepiece::class)->finalize() ?? latestCapture();
        $rows = $exploded->rows('livewire');

        expect(collect($rows)->pluck('exception')->filter()->first()['message'])->toBe('component exploded');

        $summary = (new LivewireLens())->summarize($exploded);

        expect($summary['totals']['livewire_components'])->toBeGreaterThanOrEqual(1)
            ->and(implode(' ', $summary['warnings']))->toContain('component exploded');
    });

    it('summarizes nothing without components', function (): void {
        $this->get('/nothing-here');

        expect((new LivewireLens())->summarize(latestCapture()))->toBe([]);
    });
});
