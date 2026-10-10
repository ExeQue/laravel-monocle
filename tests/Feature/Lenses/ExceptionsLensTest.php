<?php

declare(strict_types=1);

use ExeQue\Monocle\Lenses\ExceptionsLens;
use Illuminate\Support\Facades\Route;

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

        $entry = latestEntry();
        $rows = $entry->rows('exceptions');

        expect($entry->status)->toBe('500')
            ->and($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray(['class' => RuntimeException::class, 'message' => 'kaboom', 'code' => 42])
            ->and($rows[0]['file'])->toContain('ExceptionsLensTest.php:')
            ->and($rows[0]['frames'])->toBeArray()
            ->and($rows[0]['previous'])->toMatchArray(['class' => LogicException::class, 'message' => 'root cause']);

        $summary = (new ExceptionsLens())->summarize($entry);

        expect($summary['totals'])->toBe(['exceptions' => 1])
            ->and($summary['warnings'][0])->toStartWith('RuntimeException: kaboom (');
    });

    it('does not record exceptions the handler ignores', function (): void {
        $this->get('/missing')->assertNotFound();

        $entry = latestEntry();

        expect($entry->status)->toBe('404')
            ->and($entry->rows('exceptions'))->toBe([])
            ->and((new ExceptionsLens())->summarize($entry))->toBe([]);
    });
});
