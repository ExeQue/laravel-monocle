<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Middleware\TimedMiddleware;
use ExeQue\Monocle\Lenses\MiddlewareLens;
use ExeQue\Monocle\Reader\SummaryBuilder;
use ExeQue\Monocle\Reader\TimelineBuilder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Fixtures\Middleware\Abort;
use Tests\Fixtures\Middleware\Fail;
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

    it('records the status a thrown exception turns into and rethrows it', function (string $with, int $status, string $exception): void {
        Route::get('/failing', fn () => 'never')->middleware([SleepBefore::class, Fail::class.':'.$with]);

        $this->getJson('/failing')->assertStatus($status);

        $rows = collect(latestCapture()->rows('middleware'))->keyBy(fn (array $row): string => class_basename($row['middleware']));

        expect($rows['Fail'])->toMatchArray(['passed' => false, 'short_circuited_with' => $status, 'threw' => $exception])
            ->and($rows['SleepBefore'])->toMatchArray(['passed' => true, 'short_circuited_with' => null, 'threw' => null])
            ->and(app(MiddlewareLens::class)->summarize(latestCapture())->warnings)
            ->toContain(sprintf('Middleware Fail short-circuited the request by throwing %s (%d).', $exception, $status));
    })->with([
        'http exception' => ['http', 409, \Symfony\Component\HttpKernel\Exception\HttpException::class],
        'authorization' => ['denied', 403, \Illuminate\Auth\Access\AuthorizationException::class],
        'authorization with a status' => ['hidden', 404, \Illuminate\Auth\Access\AuthorizationException::class],
        'authentication' => ['guest', 401, \Illuminate\Auth\AuthenticationException::class],
        'validation' => ['invalid', 422, \Illuminate\Validation\ValidationException::class],
        'anything else' => ['boom', 500, RuntimeException::class],
    ]);

    it('records the throttle middleware as answering 429', function (): void {
        Route::get('/throttled', fn () => 'ok')->middleware('throttle:1,1');

        $this->get('/throttled')->assertOk();
        $this->get('/throttled')->assertStatus(429);

        expect(collect(latestCapture()->rows('middleware'))->firstWhere('passed', false))->toMatchArray(['short_circuited_with' => 429]);
    });

    it('lists every middleware in order with its time before and after the rest of the request', function (): void {
        $this->get('/timed')->assertOk();

        $rows = collect(latestCapture()->rows('middleware'))->keyBy(fn (array $row): string => class_basename($row['middleware']));

        expect($rows['SleepBefore'])->toMatchArray(['parameters' => ['5'], 'group' => 'route', 'passed' => true, 'short_circuited_with' => null])
            ->and($rows['SleepBefore']['request_ms'])->toBeGreaterThanOrEqual(4)
            ->and($rows['SleepAfter']['response_ms'])->toBeGreaterThanOrEqual(4)
            ->and($rows['SleepAfter']['duration'])->toBe(round($rows['SleepAfter']['request_ms'] + $rows['SleepAfter']['response_ms'], 3))
            ->and($rows->keys()->search('SleepBefore'))->toBeLessThan($rows->keys()->search('SleepAfter'));

        $this->get('/aborted')->assertStatus(418);

        expect(collect(latestCapture()->rows('middleware'))->firstWhere('middleware', Abort::class))->toMatchArray(['passed' => false, 'short_circuited_with' => 418, 'response_ms' => null]);
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

    it('skips closure middleware and classes that do not exist', function (): void {
        $missing = 'Tests\\Fixtures\\Middleware\\Missing';
        $route = new RoutingRoute(['GET'], '/mixed', ['uses' => fn () => 'ok', 'middleware' => [fn ($request, $next) => $next($request), $missing, SleepAfter::class]]);

        event(new RouteMatched($route, Request::create('/mixed')));

        expect(app(SleepAfter::class))->toBeInstanceOf(TimedMiddleware::class)
            ->and(app()->bound($missing))->toBeFalse();
    });

    it('warns about slow middleware whose class is unknown or missing', function (): void {
        $lens = app(MiddlewareLens::class);
        $lens->configure(['slow_ms' => 1]);

        $capture = new Capture('capture', CaptureType::Request, microtime(true));

        foreach (['Anonymous' => [], 'Missing' => ['class' => 'Tests\\Fixtures\\Middleware\\Missing']] as $name => $meta) {
            $span = new Span($name, $name, 'middleware.request', null, null, null);
            $span->start = 0.0;
            $span->end = 10.0;
            $span->meta = $meta;
            $capture->spans[$name] = $span;
        }

        expect($lens->summarize($capture)->warnings)->toBe([
            'Middleware Anonymous spent 10.0 ms of its own time.',
            'Middleware Missing spent 10.0 ms of its own time.',
        ]);
    });

    it('wraps route middleware by name without global middleware when the kernel cannot be resolved', function (): void {
        $this->app->bind(Kernel::class, fn () => throw new RuntimeException('no kernel'));

        $lens = new MiddlewareLens(app(Eyepiece::class));
        $lens->boot($this->app);

        $route = new RoutingRoute(['GET'], '/plain', ['uses' => fn () => 'ok', 'middleware' => [SleepBefore::class.':3', fn ($request, $next) => $next($request)]]);

        event(new RouteMatched($route, Request::create('/plain')));

        expect(app(SleepBefore::class))->toBeInstanceOf(TimedMiddleware::class);
    });

    it('presents middleware as a table with own time and short circuits', function (): void {
        $presentation = app(MiddlewareLens::class)->presentation();

        expect($presentation->tabView)->toBe('monocle::tabs.middleware')
            ->and(array_keys($presentation->columns))->toBe(['middleware', 'group', 'request_ms', 'response_ms', 'passed', 'duration'])
            ->and($presentation->detailsOnly)->toBe(['parameters', 'short_circuited_with', 'threw', 'slow'])
            ->and($presentation->groupings)->toBe(['group' => 'by group'])
            ->and($presentation->highlights)->toBe(['slow' => 'warn']);
    });
});
