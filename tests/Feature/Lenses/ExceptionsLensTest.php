<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Lenses\ExceptionsLens;
use Illuminate\Support\Facades\Route;
use LogicException;
use RuntimeException;

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
            ->and($rows[0]['previous'][0])->toMatchArray(['class' => LogicException::class, 'message' => 'root cause']);

        $summary = app(ExceptionsLens::class)->summarize($capture);

        expect($summary->totals())->toBe(['exceptions' => 1])
            ->and($summary->warnings()[0])->toStartWith('RuntimeException: kaboom (');
    });

    it('does not record exceptions the handler ignores', function (): void {
        $this->get('/missing')->assertNotFound();

        $capture = latestCapture();

        expect($capture->status)->toBe('404')
            ->and($capture->rows('exceptions'))->toBe([])
            ->and(app(ExceptionsLens::class)->summarize($capture))->toEqual(new \ExeQue\Monocle\Data\Summary());
    });
});
