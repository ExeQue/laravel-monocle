<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\CacheLens;
use Illuminate\Cache\Events\CacheFlushed;
use Illuminate\Cache\Events\CacheFlushFailed;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Support\Facades\Cache;

covers(CacheLens::class);

describe(CacheLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('records misses, writes, hits and forgets with durations and sizes', function (): void {
        Cache::get('user:1');
        Cache::put('user:1', ['name' => 'Ada'], 60);
        Cache::get('user:1');
        Cache::forget('user:1');

        $rows = app(Eyepiece::class)->finalize()->rows('cache');

        expect(array_column($rows, 'type'))->toBe(['miss', 'write', 'hit', 'forget'])
            ->and($rows[1]['ttl'])->toBe(60)
            ->and($rows[1]['value_size_bytes'])->toBeGreaterThan(0)
            ->and($rows[1])->not->toHaveKey('value')
            ->and($rows[2]['duration'])->toBeNumeric()
            ->and($rows[2]['store'])->toBe('array')
            ->and($rows[2]['origin']['file'])->toContain('CacheLensTest.php');
    });

    it('stores values only when configured', function (): void {
        $lens = app(CacheLens::class);
        $lens->configure(['values' => true]);
        $lens->boot($this->app, app(Eyepiece::class));

        Cache::put('k', 'v', 60);

        $rows = app(Eyepiece::class)->finalize()->rows('cache');

        expect(collect($rows)->where('type', 'write')->pluck('value')->filter()->all())->toContain('v');
    });

    it('masks values of keys that match the input masking pattern', function (): void {
        $lens = app(CacheLens::class);
        $lens->configure(['values' => true]);
        $lens->boot($this->app, app(Eyepiece::class));

        Cache::put('api_token', 'secret-value', 60);
        Cache::put('settings', ['theme' => 'dark', 'password' => 'secret-value'], 60);

        $values = collect(app(Eyepiece::class)->finalize()->rows('cache'))->where('type', 'write')->pluck('value', 'key');

        expect($values['api_token'])->not->toBe('secret-value')
            ->and($values['settings']['theme'])->toBe('dark')
            ->and($values['settings']['password'])->not->toBe('secret-value');
    });

    it('summarizes hit rate and hottest keys', function (): void {
        Cache::put('a', 1, 60);
        Cache::get('a');
        Cache::get('a');
        Cache::get('b');

        $summary = app(CacheLens::class)->summarize(app(Eyepiece::class)->finalize());

        expect($summary->totals)->toMatchArray(['cache_hits' => 2, 'cache_misses' => 1, 'cache_writes' => 1])
            ->and($summary->totals['cache_hit_rate'])->toBe(66.7)
            ->and(array_key_first($summary->totals['cache_hottest_keys']))->toBe('a');
    });

    it('numbers reads, points repeats at the first read and warns about keys read often', function (): void {
        $cache = Cache::store('file');
        $cache->put('a', 1, 60);
        $cache->get('a');
        $cache->get('a');
        $cache->get('b');
        $cache->get('a');

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('cache');
        $lens = app(CacheLens::class);
        $lens->configure(['repeat_threshold' => 3, 'repeat_ms' => 0]);

        expect(array_column($rows, 'n'))->toBe([1, 2, 3, 4, 5])
            ->and(array_column($rows, 'repeat_of', 'n'))->toBe([2 => null, 3 => 2, 4 => null, 5 => 2])
            ->and($lens->summarize($capture)->warnings)->toHaveCount(1)
            ->and($lens->summarize($capture)->warnings[0])->toMatch('/^Cache key a read 3 times \([\d.]+ ms\) from /')->toContain('CacheLensTest.php:')
            ->and($lens->summarize($capture)->warnings[0])->toEndWith('Cache::memo() or once().');

        $lens->configure(['repeat_threshold' => 4]);

        expect($lens->summarize($capture)->warnings)->toBe([]);

        $lens->configure(['repeat_threshold' => 3, 'repeat_ms' => 10_000]);

        expect($lens->summarize($capture)->warnings)->toBe([]);
    });

    it('warns about cache calls slower than slow_ms', function (): void {
        $capture = app(Eyepiece::class)->finalize();
        $capture->sections['cache'] = [
            ['n' => 1, 'type' => 'hit', 'key' => 'rates', 'store' => 'redis', 'duration' => 34.2],
            ['n' => 2, 'type' => 'write', 'key' => 'quick', 'store' => 'redis', 'duration' => 0.4],
        ];
        $lens = app(CacheLens::class);

        config(['cache.stores.sessions' => ['driver' => 'redis']]);
        $capture->sections['cache'][] = ['n' => 3, 'type' => 'miss', 'key' => 'cart', 'store' => 'sessions', 'duration' => 25.0];

        expect($lens->summarize($capture)->warnings)->toBe([
            'Slow cache hit (34.2 ms) on the "redis" store: rates',
            'Slow cache miss (25.0 ms) on the "sessions" store (redis): cart',
        ]);

        $lens->configure(['slow_ms' => 50]);

        expect($lens->summarize($capture)->warnings)->toBe([]);
    });
    it('records failed writes, failed forgets, flushes and failed flushes', function (): void {
        event(new KeyWriteFailed('array', 'k', 'v', 30));
        event(new KeyForgetFailed('array', 'k'));
        event(new CacheFlushed('array'));
        event(new CacheFlushFailed('array'));

        $rows = app(Eyepiece::class)->finalize()->rows('cache');

        expect(array_column($rows, 'type'))->toBe(['write_failed', 'forget_failed', 'flush', 'flush_failed'])
            ->and($rows[3])->toMatchArray(['store' => 'array', 'key' => null, 'duration' => null])
            ->and($rows[0]['ttl'])->toBe(30)
            ->and($rows[2])->toMatchArray(['store' => 'array', 'key' => null, 'duration' => null]);
    });

    it('records nothing while no capture is recording', function (): void {
        app(Eyepiece::class)->discard();

        Cache::get('a');
        event(new CacheFlushed('array'));

        expect(app(Eyepiece::class)->active()->rows('cache'))->toBe([]);
    });

    it('does not warn about repeated reads on a store with the array driver', function (): void {
        config(['cache.stores.local' => ['driver' => 'array']]);

        foreach (['local', null] as $store) {
            Cache::store($store)->get('a');
            Cache::store($store)->get('a');
        }

        $capture = app(Eyepiece::class)->finalize();
        $lens = app(CacheLens::class);
        $lens->configure(['repeat_threshold' => 2, 'repeat_ms' => 0]);

        expect(config('cache.default'))->toBe('array')
            ->and($lens->summarize($capture)->warnings)->toBe([]);
    });

    it('names stores without a configured driver and skips reads without a key or from vendor code', function (): void {
        $capture = app(Eyepiece::class)->finalize();
        $vendor = ['file' => 'vendor/acme/lib/Cache.php', 'line' => 3, 'vendor' => true];
        $capture->sections['cache'] = [
            ['n' => 1, 'type' => 'hit', 'key' => 'a', 'store' => null, 'duration' => 30.0],
            ['n' => 2, 'type' => 'miss', 'key' => 'b', 'store' => 'unknown', 'duration' => 30.0],
            ['n' => 3, 'type' => 'hit', 'store' => 'array', 'duration' => 0.1],
            ['n' => 4, 'type' => 'hit', 'key' => 'v', 'store' => 'array', 'duration' => 0.1, 'origin' => $vendor],
            ['n' => 5, 'type' => 'hit', 'key' => 'v', 'store' => 'array', 'duration' => 0.1, 'origin' => $vendor],
        ];
        $lens = app(CacheLens::class);
        $lens->configure(['repeat_threshold' => 2]);

        expect($lens->summarize($capture)->warnings)->toBe([
            'Slow cache hit (30.0 ms) on the default store: a',
            'Slow cache miss (30.0 ms) on the "unknown" store: b',
        ]);
    });

    it('describes itself for the agent and the dashboard', function (): void {
        $lens = app(CacheLens::class);
        $presentation = $lens->presentation();

        expect($lens->description())->toContain('repeat_of')
            ->and($lens->advice())->toStartWith('Look here when data is stale')
            ->and($presentation->tabView)->toBe('monocle::tabs.cache')
            ->and($presentation->formats)->toHaveKeys(['type', 'repeat_of'])
            ->and($presentation->highlights)->toBe(['repeat_of' => 'warn', 'slow' => 'warn']);
    });
    it('records no size for a value that cannot be serialized', function (): void {
        event(new \Illuminate\Cache\Events\KeyWritten('array', 'callback', fn (): int => 1, 60));

        $row = app(Eyepiece::class)->finalize()->rows('cache')[0];

        expect($row['type'])->toBe('write')
            ->and($row)->toHaveKey('value_size_bytes')
            ->and($row['value_size_bytes'])->toBeNull();
    });
});
