<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Lenses\Middleware\TimedMiddleware;
use ExeQue\Monocle\Lenses\MiddlewareLens;
use ExeQue\Monocle\Reader\SummaryBuilder;
use ExeQue\Monocle\Reader\TimelineBuilder;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Middleware\Abort;
use Tests\Fixtures\Middleware\SleepAfter;
use Tests\Fixtures\Middleware\SleepBefore;

covers(MiddlewareLens::class, TimedMiddleware::class, SummaryBuilder::class, TimelineBuilder::class);

describe(MiddlewareLens::class, function (): void {
    beforeEach(function (): void {
        SleepAfter::$terminated = false;

        Route::get('/timed', fn () => 'ok')->middleware([SleepBefore::class.':5', SleepAfter::class]);
        Route::get('/aborted', fn () => 'never')->middleware([SleepBefore::class, Abort::class]);
    });

    $span = fn (string $kind, string $name): ?Span => collect(latestCapture()->spansOfKind($kind))->first(fn (Span $span): bool => $span->name === $name);

    it('records the request and the response side of every middleware as separate spans', function () use ($span): void {
        $this->get('/timed')->assertOk();

        $outerRequest = $span('middleware.request', 'SleepBefore');
        $innerRequest = $span('middleware.request', 'SleepAfter');
        $innerResponse = $span('middleware.response', 'SleepAfter');
        $outerResponse = $span('middleware.response', 'SleepBefore');
        $controller = latestCapture()->spans['controller'] ?? null;

        expect($outerRequest->meta)->toMatchArray(['passed' => true, 'parameters' => ['5'], 'group' => 'route'])
            ->and($outerRequest->duration())->toBeGreaterThanOrEqual(4)
            ->and($innerResponse->duration())->toBeGreaterThanOrEqual(4)
            ->and($outerResponse->duration())->toBeLessThan(4)
            ->and($outerRequest->end)->toBeLessThanOrEqual($innerRequest->start)
            ->and($innerResponse->end)->toBeLessThanOrEqual($outerResponse->start)
            ->and($innerRequest->parentId)->toBe($outerRequest->parentId)
            ->and($outerResponse->parentId)->toBe($innerResponse->parentId)
            ->and(latestCapture()->spansOfKind('middleware'))->toBe([])
            ->and($controller?->start)->toBe($innerRequest->end);
    });

    it('records short circuits on the request span without a response span', function () use ($span): void {
        $this->get('/aborted')->assertStatus(418);

        expect($span('middleware.request', 'Abort')->meta)->toMatchArray(['passed' => false, 'short_circuited_with' => 418])
            ->and($span('middleware.response', 'Abort'))->toBeNull()
            ->and(latestCapture()->spans)->not->toHaveKey('controller');
    });

    it('splits middleware time into a request and a response phase', function (): void {
        $this->get('/timed')->assertOk();

        $phases = array_column(app(SummaryBuilder::class)->breakdown(latestCapture())['phases'], 'ms', 'name');
        $sum = fn (string $kind): float => round(array_sum(array_map(fn (Span $span): float => $span->duration(), latestCapture()->spansOfKind($kind))), 3);

        expect(array_keys($phases))->toContain('middleware (request)', 'controller', 'middleware (response)')
            ->and($phases['middleware (request)'])->toBe($sum('middleware.request'))
            ->and($phases['middleware (response)'])->toBe($sum('middleware.response'))
            ->and($phases['middleware (request)'])->toBeGreaterThanOrEqual(4)
            ->and($phases['middleware (response)'])->toBeGreaterThanOrEqual(4);
    });

    it('includes both middleware span kinds when the timeline is filtered on middleware', function (): void {
        $this->get('/timed')->assertOk();

        $kinds = array_unique(array_column(app(TimelineBuilder::class)->build(latestCapture(), ['middleware']), 'kind'));
        sort($kinds);

        expect($kinds)->toBe(['middleware.request', 'middleware.response', 'middleware.terminate']);
    });

    it('forwards and times terminate only for terminable middleware', function (): void {
        $this->get('/timed');

        $terminate = collect(latestCapture()->spansOfKind('middleware.terminate'))->map(fn (Span $span): string => $span->name)->all();

        expect(SleepAfter::$terminated)->toBeTrue()
            ->and($terminate)->toContain('SleepAfter::terminate')
            ->and($terminate)->not->toContain('SleepBefore::terminate');
    });

    it('warns about slow middleware in the summary', function (): void {
        $lens = app(MiddlewareLens::class);
        $lens->configure(['slow_ms' => 1]);

        $this->get('/timed');

        $summary = $lens->summarize(latestCapture());
        $own = collect(latestCapture()->spans)
            ->filter(fn (Span $span): bool => $span->name === 'SleepBefore' && str_starts_with($span->kind, 'middleware.'))
            ->sum(fn (Span $span): float => $span->duration());

        expect($summary->totals['middleware_count'])->toBe(2)
            ->and($summary->warnings)->toContain(sprintf('Middleware SleepBefore spent %.1f ms of its own time.', $own));
    });
});
