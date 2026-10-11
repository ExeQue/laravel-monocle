<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Lenses\ExceptionsLens;
use Illuminate\Support\Facades\Route;
use LogicException;
use Mockery;
use RuntimeException;
use Throwable;

covers(ExceptionsLens::class);

describe(ExceptionsLens::class, function (): void {
    beforeEach(function (): void {
        config(['logging.default' => 'null']);

        Route::get('/boom', function (): never {
            throw new RuntimeException('kaboom', 42, new LogicException('root cause'));
        });
        Route::get('/missing', fn () => abort(404));
    });

    it('records reported exceptions with user-land frames and the previous exception', function (): void {
        $this->get('/boom')->assertStatus(500);

        $capture = latestCapture();
        $rows = $capture->rows('exceptions');

        expect($capture->status)->toBe('500')
            ->and($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray(['class' => RuntimeException::class, 'message' => 'kaboom', 'code' => 42])
            ->and($rows[0]['file'])->toContain('ExceptionsLensTest.php:')
            ->and($rows[0]['frames'])->toBeArray()
            ->and($rows[0]['previous'][0])->toMatchArray(['class' => LogicException::class, 'message' => 'root cause'])
            ->and($rows[0])->toMatchArray(['source' => 'native', 'unhandled' => true]);

        $summary = app(ExceptionsLens::class)->summarize($capture);

        expect($summary->totals)->toBe(['exceptions' => 1, 'exceptions_unhandled' => 1, 'exceptions_distinct' => 0, 'exceptions_from_native' => 1])
            ->and($summary->errors[0])->toStartWith('RuntimeException: kaboom (');
    });

    it('counts exceptions the request carried on after by source and class', function (): void {
        Route::get('/reported', function () {
            report(new RuntimeException('first'));
            report(new RuntimeException('second'));
            report(new \Illuminate\Http\Client\ConnectionException('offline'));
            report(new class ('ours') extends RuntimeException {});

            return 'ok';
        });

        $this->get('/reported')->assertOk();

        $capture = latestCapture();

        expect(array_column($capture->rows('exceptions'), 'source'))->toBe(['native', 'native', 'laravel/framework', 'app'])
            ->and(array_column($capture->rows('exceptions'), 'unhandled'))->toBe([false, false, false, false])
            ->and(app(ExceptionsLens::class)->summarize($capture)->totals)->toBe([
                'exceptions' => 4,
                'exceptions_unhandled' => 0,
                'exceptions_distinct' => 3,
                'exceptions_from_native' => 2,
                'exceptions_from_app' => 1,
                'exceptions_from_laravel/framework' => 1,
            ]);
    });

    it('marks nothing unhandled once the capture is gone or forgotten', function (): void {
        $eyepiece = app(\ExeQue\Monocle\Eyepiece::class);
        $lens = collect(app(\ExeQue\Monocle\LensRegistry::class)->booted())->first(fn ($lens): bool => $lens instanceof ExceptionsLens);
        $exception = new RuntimeException('late');
        $response = new \Illuminate\Http\Response()->withException($exception);

        $eyepiece->start(\ExeQue\Monocle\Enums\CaptureType::Request);
        report($exception);
        $capture = $eyepiece->finalize();

        event(new \Illuminate\Foundation\Http\Events\RequestHandled(request(), $response));

        $eyepiece->start(\ExeQue\Monocle\Enums\CaptureType::Request);
        report($exception);
        $lens->reset();

        event(new \Illuminate\Foundation\Http\Events\RequestHandled(request(), $response));

        expect($capture->rows('exceptions')[0]['unhandled'])->toBeFalse()
            ->and($eyepiece->finalize()->rows('exceptions')[0]['unhandled'])->toBeFalse();
    });

    it('does not record exceptions the handler ignores', function (): void {
        $this->get('/missing')->assertNotFound();

        $capture = latestCapture();

        expect($capture->status)->toBe('404')
            ->and($capture->rows('exceptions'))->toBe([])
            ->and(app(ExceptionsLens::class)->summarize($capture))->toEqual(\ExeQue\Monocle\Data\Summary::make());
    });
    it('names frames of plain functions and skips frames without a file', function (): void {
        $eyepiece = app(\ExeQue\Monocle\Eyepiece::class);
        $eyepiece->start(\ExeQue\Monocle\Enums\CaptureType::Request);

        try {
            array_map(fn (): never => throw new RuntimeException('mapped'), [1]);
        } catch (RuntimeException $exception) {
            report($exception);
        }

        $frames = $eyepiece->finalize()->rows('exceptions')[0]['frames'];

        expect($frames[0])->toMatch('/ExceptionsLensTest\.php:\d+ array_map$/');
    });

    it('records nothing while no capture is recording', function (): void {
        $eyepiece = app(\ExeQue\Monocle\Eyepiece::class);
        $eyepiece->start(\ExeQue\Monocle\Enums\CaptureType::Request);
        $eyepiece->discard();

        report(new RuntimeException('unrecorded'));

        expect($eyepiece->active()->rows('exceptions'))->toBe([]);
    });

    it('stays out of an exception handler that takes no reportable callbacks', function (): void {
        $app = Mockery::mock(\Illuminate\Contracts\Foundation\Application::class);
        $app->shouldReceive('basePath')->andReturn('/app');
        $app->shouldReceive('make')->with(\Illuminate\Contracts\Debug\ExceptionHandler::class)->andReturn(Mockery::mock(\Illuminate\Contracts\Debug\ExceptionHandler::class));

        $lens = new ExceptionsLens(app(\ExeQue\Monocle\Eyepiece::class));

        expect(fn () => $lens->boot($app))->not->toThrow(Throwable::class);
    });

    it('describes itself for the agent and the dashboard', function (): void {
        $lens = app(ExceptionsLens::class);
        $presentation = $lens->presentation();

        expect($lens->description())->toContain('user-land frames')
            ->and($lens->advice())->toStartWith('Look here first when something went wrong')
            ->and($presentation->tabView)->toBe('monocle::tabs.exceptions')
            ->and($presentation->detailsView)->toBe('monocle::details.exception')
            ->and($presentation->groupings)->toBe(['class' => 'by exception', 'source' => 'by source'])
            ->and($presentation->formats)->toBe(['message' => ['truncate', 80]]);
    });
});
