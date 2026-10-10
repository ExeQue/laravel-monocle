<?php

declare(strict_types=1);

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Lenses\Middleware\TimedMiddleware;
use ExeQue\Monocle\Lenses\MiddlewareLens;
use ExeQue\Monocle\Reader\SummaryBuilder;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Middleware\Abort;
use Tests\Fixtures\Middleware\SleepAfter;
use Tests\Fixtures\Middleware\SleepBefore;

covers(MiddlewareLens::class, TimedMiddleware::class, SummaryBuilder::class);

describe(MiddlewareLens::class, function (): void {
    beforeEach(function (): void {
        SleepAfter::$terminated = false;

        Route::get('/timed', fn () => 'ok')->middleware([SleepBefore::class.':5', SleepAfter::class]);
        Route::get('/aborted', fn () => 'never')->middleware([SleepBefore::class, Abort::class]);
    });

    $byName = fn (string $name): ?Span => collect(latestCapture()->spansOfKind('middleware'))->first(fn (Span $span): bool => $span->name === $name);

    it('times before and after $next for every route middleware', function () use ($byName): void {
        $this->get('/timed')->assertOk();

        $before = $byName('SleepBefore');
        $after = $byName('SleepAfter');

        expect($before)->not->toBeNull()
            ->and($before->meta['passed'])->toBeTrue()
            ->and($before->meta['parameters'])->toBe(['5'])
            ->and($before->meta['before_ms'])->toBeGreaterThanOrEqual(4)
            ->and($before->meta['after_ms'])->toBeLessThan(4)
            ->and($after->meta['after_ms'])->toBeGreaterThanOrEqual(4)
            ->and($after->parentId)->toBe($before->id)
            ->and($before->meta['group'])->toBe('route');
    });

    it('records the work before and after $next as child spans', function () use ($byName): void {
        $this->get('/timed')->assertOk();

        $children = fn (Span $parent): array => collect(latestCapture()->spans)
            ->filter(fn (Span $span): bool => $span->parentId === $parent->id)
            ->mapWithKeys(fn (Span $span): array => [$span->kind => $span])
            ->all();

        $outer = $byName('SleepBefore');
        $inner = $byName('SleepAfter');
        $outerChildren = $children($outer);
        $innerChildren = $children($inner);

        expect(array_keys($outerChildren))->toBe(['middleware.before', 'middleware', 'middleware.after'])
            ->and($outerChildren['middleware.before']->duration())->toBeGreaterThanOrEqual(4)
            ->and($outerChildren['middleware.before']->end)->toBeLessThanOrEqual($inner->start)
            ->and($outerChildren['middleware.after']->start)->toBeGreaterThanOrEqual($inner->end)
            ->and($outerChildren['middleware.after']->end)->toBeLessThanOrEqual($outer->end)
            ->and($innerChildren['middleware.after']->duration())->toBeGreaterThanOrEqual(4);
    });

    it('splits middleware time into a request and a response phase', function (): void {
        $this->get('/timed')->assertOk();

        $spans = latestCapture()->spansOfKind('middleware');
        $phases = array_column(app(SummaryBuilder::class)->breakdown(latestCapture())['phases'], 'ms', 'name');
        $sum = fn (string $part): float => round(array_sum(array_map(fn (Span $span): float => (float) $span->meta[$part], $spans)), 3);

        expect(array_keys($phases))->toContain('middleware (request)', 'controller', 'middleware (response)')
            ->and($phases)->not->toHaveKey('middleware')
            ->and($phases['middleware (request)'])->toBe($sum('before_ms'))
            ->and($phases['middleware (response)'])->toBe($sum('after_ms'))
            ->and($phases['middleware (request)'])->toBeGreaterThanOrEqual(4)
            ->and($phases['middleware (response)'])->toBeGreaterThanOrEqual(4);
    });

    it('only records the before span when the middleware short-circuits', function () use ($byName): void {
        $this->get('/aborted')->assertStatus(418);

        $abort = $byName('Abort');
        $kinds = collect(latestCapture()->spans)->filter(fn (Span $span): bool => $span->parentId === $abort->id)->map->kind->values()->all();

        expect($kinds)->toBe(['middleware.before']);
    });

    it('records short circuits', function () use ($byName): void {
        $this->get('/aborted')->assertStatus(418);

        $abort = $byName('Abort');

        expect($abort->meta['passed'])->toBeFalse()
            ->and($abort->meta['short_circuited_with'])->toBe(418)
            ->and(latestCapture()->spans)->not->toHaveKey('controller');
    });

    it('forwards and times terminate only for terminable middleware', function (): void {
        $this->get('/timed');

        $terminate = collect(latestCapture()->spansOfKind('middleware.terminate'))->map(fn (Span $span): string => $span->name)->all();

        expect(SleepAfter::$terminated)->toBeTrue()
            ->and($terminate)->toContain('SleepAfter::terminate')
            ->and($terminate)->not->toContain('SleepBefore::terminate');
    });

    it('warns about slow middleware in the summary', function (): void {
        $lens = new MiddlewareLens();
        $lens->configure(['slow_ms' => 1]);

        $this->get('/timed');

        $summary = $lens->summarize(latestCapture());
        $sleepBefore = collect(latestCapture()->spansOfKind('middleware'))->firstWhere('name', 'SleepBefore');
        $own = (float) $sleepBefore->meta['before_ms'] + (float) $sleepBefore->meta['after_ms'];

        expect($summary['totals']['middleware_count'])->toBe(2)
            ->and($summary['warnings'])->toContain(sprintf('Middleware SleepBefore spent %.1f ms of its own time.', $own));
    });
});
