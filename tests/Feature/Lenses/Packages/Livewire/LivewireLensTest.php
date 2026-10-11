<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Livewire;

use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Livewire\LivewireLens;
use Livewire\Livewire;
use RuntimeException;
use Tests\Fixtures\Livewire\Counter;
use Tests\Fixtures\Livewire\LoginForm;

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
            ->and($rows[0]['time'])->toBeLessThan($capture->duration())
            ->and($rows[0]['duration'])->toBeNumeric()
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

        $summary = app(LivewireLens::class)->summarize($exploded);

        expect($summary->totals['livewire_components'])->toBeGreaterThanOrEqual(1)
            ->and(implode(' ', $summary->errors))->toContain('component exploded');
    });

    it('masks parameters and updates whose name matches the input pattern', function (): void {
        $component = Livewire::test(LoginForm::class)->set('password', 'hunter22hunter22');
        $updates = latestCapture()->rows('livewire')[0]['updates'];

        $component->set('email', 'ada@example.com');
        $updates = [...$updates, ...latestCapture()->rows('livewire')[0]['updates']];

        $component->call('login', 'hunter22hunter22', 'ada@example.com');
        $login = latestCapture()->rows('livewire')[0]['calls'][0];

        $component->call('$commit');
        $magic = latestCapture()->rows('livewire')[0]['calls'][0];

        expect($updates)->toBe([
            ['path' => 'password', 'value' => 'hunt****'],
            ['path' => 'email', 'value' => 'ada@example.com'],
        ])
            ->and($login['params'])->toBe(['hunt****', 'ada@example.com'])
            ->and($magic)->toMatchArray(['method' => '$commit', 'params' => []]);
    });

    it('summarizes nothing without components', function (): void {
        $this->get('/nothing-here');

        expect(app(LivewireLens::class)->summarize(latestCapture()))->toEqual(\ExeQue\Monocle\Data\Summary::make());
    });

    it('leaves components alone that mounted while nothing was recording', function (): void {
        config(['monocle.except.paths' => ['*']]);

        Livewire::test(Counter::class)->call('increment', 1)->assertSet('count', 1);

        expect(latestCapture())->toBeNull();
    });

    it('describes its rows and shows them on the livewire tab', function (): void {
        $lens = app(LivewireLens::class);

        expect($lens->description())->toContain('render_ms')
            ->and($lens->advice())->toStartWith('Look here for Livewire components')
            ->and($lens->presentation()->tabView)->toBe('monocle::tabs.livewire');
    });
});
