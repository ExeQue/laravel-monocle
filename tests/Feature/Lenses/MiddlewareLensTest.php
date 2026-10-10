<?php

declare(strict_types=1);

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Lenses\Middleware\TimedMiddleware;
use ExeQue\Monocle\Lenses\MiddlewareLens;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Middleware\Abort;
use Tests\Fixtures\Middleware\SleepAfter;
use Tests\Fixtures\Middleware\SleepBefore;

covers(MiddlewareLens::class, TimedMiddleware::class);

describe(MiddlewareLens::class, function (): void {
    beforeEach(function (): void {
        SleepAfter::$terminated = false;

        Route::get('/timed', fn () => 'ok')->middleware([SleepBefore::class . ':5', SleepAfter::class]);
        Route::get('/aborted', fn () => 'never')->middleware([SleepBefore::class, Abort::class]);
    });

    $byName = fn (string $name): ?Span => collect(latestEntry()->spansOfKind('middleware'))->first(fn (Span $span): bool => $span->name === $name);

    it('times before and after $next for every route middleware', function () use ($byName): void {
        $this->get('/timed')->assertOk();

        $before = $byName('SleepBefore');
        $after = $byName('SleepAfter');

        expect($before)->not->toBeNull()
            ->and($before->meta['passed'])->toBeTrue()
            ->and($before->meta['parameters'])->toBe(['5'])
            ->and($before->meta['before_ms'])->toBeGreaterThanOrEqual(4)
            ->and($before->meta['after_ms'])->toBeLessThan($before->meta['before_ms'])
            ->and($after->meta['after_ms'])->toBeGreaterThanOrEqual(4)
            ->and($after->parentId)->toBe($before->id)
            ->and($before->meta['group'])->toBe('route');
    });

    it('records short circuits', function () use ($byName): void {
        $this->get('/aborted')->assertStatus(418);

        $abort = $byName('Abort');

        expect($abort->meta['passed'])->toBeFalse()
            ->and($abort->meta['short_circuited_with'])->toBe(418)
            ->and(latestEntry()->spans)->not->toHaveKey('controller');
    });

    it('forwards and times terminate only for terminable middleware', function (): void {
        $this->get('/timed');

        $terminate = collect(latestEntry()->spansOfKind('middleware.terminate'))->map(fn (Span $span): string => $span->name)->all();

        expect(SleepAfter::$terminated)->toBeTrue()
            ->and($terminate)->toContain('SleepAfter::terminate')
            ->and($terminate)->not->toContain('SleepBefore::terminate');
    });

    it('warns about slow middleware in the summary', function (): void {
        $lens = new MiddlewareLens();
        $lens->configure(['slow_ms' => 1]);

        $this->get('/timed');

        $summary = $lens->summarize(latestEntry());

        expect($summary['totals']['middleware_count'])->toBe(2)
            ->and($summary['warnings'])->toContain(sprintf('Middleware SleepBefore spent %.1f ms of its own time.', $summary['totals']['middleware_self_ms'] - collect(latestEntry()->spansOfKind('middleware'))->firstWhere('name', 'SleepAfter')->meta['after_ms'] - collect(latestEntry()->spansOfKind('middleware'))->firstWhere('name', 'SleepAfter')->meta['before_ms']));
    });
});
