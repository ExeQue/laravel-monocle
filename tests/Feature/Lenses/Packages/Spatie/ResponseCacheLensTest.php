<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Spatie;

use ExeQue\Monocle\Lenses\Packages\Spatie\ResponseCacheLens;
use Illuminate\Support\Facades\Route;
use Spatie\ResponseCache\Facades\ResponseCache;
use Spatie\ResponseCache\Middlewares\CacheResponse;

covers(ResponseCacheLens::class);

describe(ResponseCacheLens::class, function (): void {
    beforeEach(function (): void {
        config(['responsecache.enabled' => true, 'responsecache.cache.store' => 'array']);
        Route::middleware(CacheResponse::class)->get('/home', fn () => 'home page');
    });

    it('records the miss that fills the cache and warns about the hit that skips the controller', function (): void {
        $this->get('/home')->assertOk();
        $miss = latestCapture();

        $this->get('/home')->assertOk()->assertSee('home page');
        $hit = latestCapture();

        $summary = app(ResponseCacheLens::class)->summarize($hit);

        expect(array_column($miss->rows('response_cache'), 'event'))->toBe(['miss'])
            ->and(app(ResponseCacheLens::class)->summarize($miss)['warnings'])->toBe([])
            ->and($hit->rows('response_cache')[0]['event'])->toBe('hit')
            ->and($hit->rows('response_cache')[0]['age_seconds'])->toBeInt()
            ->and($summary['totals'])->toBe(['response_cache' => 'hit'])
            ->and($summary['warnings'][0])->toStartWith('Served from the response cache');
    });

    it('records clearing the cache', function (): void {
        Route::get('/clear', function () {
            ResponseCache::clear();

            return 'cleared';
        });

        $this->get('/clear')->assertOk();

        expect(array_column(latestCapture()->rows('response_cache'), 'event'))->toContain('clearing', 'cleared');
    });
});
