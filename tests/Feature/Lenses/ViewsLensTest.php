<?php

declare(strict_types=1);

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Views\TimedEngine;
use ExeQue\Monocle\Lenses\ViewsLens;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\View\Compilers\BladeCompiler;

covers(ViewsLens::class, TimedEngine::class);

describe(ViewsLens::class, function (): void {
    beforeEach(function (): void {
        View::addLocation(__DIR__.'/../../Fixtures/views');

        Route::get('/page', fn () => view('parent', ['title' => 'Hello', 'items' => ['a', 'b', 'c']]));
    });

    it('times every render as nested spans and rows', function (): void {
        $this->get('/page')->assertOk()->assertSee('Hello');

        $capture = latestCapture();
        $spans = $capture->spansOfKind('view');
        $rows = $capture->rows('views');

        expect($spans)->toHaveCount(4)
            ->and($spans[0]->name)->toBe('parent')
            ->and($spans[1]->name)->toBe('partial')
            ->and($spans[1]->parentId)->toBe($spans[0]->id)
            ->and($spans[0]->meta['data_keys'])->toBe(['title', 'items'])
            ->and($spans[0]->meta['engine'])->toBe('blade')
            ->and(array_column($rows, 'view'))->toBe(['partial', 'partial', 'partial', 'parent'])
            ->and($rows[3]['duration'])->toBeGreaterThanOrEqual($rows[0]['duration'])
            ->and($rows[3]['path'])->toContain('parent.blade.php')
            ->and($rows[3])->not->toHaveKey('data');
    });

    it('summarizes with top-level time only and warns about repeated partials', function (): void {
        $lens = new ViewsLens();
        $lens->configure(['repeat_threshold' => 3, 'repeat_ms' => 0]);

        $this->get('/page');

        $capture = latestCapture();
        $summary = $lens->summarize($capture);
        $parent = collect($capture->spansOfKind('view'))->first(fn (Span $span): bool => $span->name === 'parent');

        expect($summary['totals']['views_rendered'])->toBe(4)
            ->and($summary['totals']['views_ms'])->toBe(round($parent->duration(), 3))
            ->and($summary['totals']['views_most_rendered'])->toBe(['partial' => 3, 'parent' => 1])
            ->and($summary['warnings'])->toHaveCount(1)
            ->and($summary['warnings'][0])->toMatch('/^View partial rendered 3 times, [\d.]+ ms in all\.$/');

        $lens->configure(['repeat_ms' => 10_000]);

        expect($lens->summarize($capture)['warnings'])->toBe([]);
    });

    it('names inline templates after the component that rendered them', function (): void {
        Blade::component(Tests\Fixtures\Components\InlineGreeting::class, 'greeting');
        Route::get('/inline', fn () => view('with-greeting')->render().Blade::render('{{ $n }}', ['n' => 1]));

        $this->get('/inline')->assertOk()->assertSee('Hi');

        $names = array_column(latestCapture()->rows('views'), 'view');

        expect($names)->toContain('<x-greeting>')
            ->and(collect($names)->first(fn (string $name): bool => str_starts_with($name, 'inline:')))->toMatch('/^inline:[0-9a-f]{8}$/');
    });

    it('includes view data when configured and forwards other engine calls', function (): void {
        $lens = app(ViewsLens::class);
        $lens->configure(['data' => true, 'engines' => ['blade']]);
        $lens->boot($this->app, app(Eyepiece::class));

        $this->get('/page');

        $rows = latestCapture()->rows('views');
        $engine = app('view.engine.resolver')->resolve('blade');

        expect(collect($rows)->firstWhere('view', 'parent')['data']['title'])->toBe('Hello')
            ->and($engine)->toBeInstanceOf(TimedEngine::class)
            ->and($engine->getCompiler())->toBeInstanceOf(BladeCompiler::class)
            ->and($engine->inner())->not->toBeInstanceOf(TimedEngine::class);
    });
});
