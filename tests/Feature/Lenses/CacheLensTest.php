<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\CacheLens;
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
            ->and($rows[2]['duration'])->toBeFloat()
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

    it('summarizes hit rate and hottest keys', function (): void {
        Cache::put('a', 1, 60);
        Cache::get('a');
        Cache::get('a');
        Cache::get('b');

        $summary = (new CacheLens())->summarize(app(Eyepiece::class)->finalize());

        expect($summary['totals'])->toMatchArray(['cache_hits' => 2, 'cache_misses' => 1, 'cache_writes' => 1])
            ->and($summary['totals']['cache_hit_rate'])->toBe(66.7)
            ->and(array_key_first($summary['totals']['cache_hottest_keys']))->toBe('a');
    });

    it('numbers reads, points repeats at the first read and warns about keys read often', function (): void {
        Cache::put('a', 1, 60);
        Cache::get('a');
        Cache::get('a');
        Cache::get('b');
        Cache::get('a');

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('cache');
        $lens = new CacheLens();

        expect(array_column($rows, 'n'))->toBe([1, 2, 3, 4, 5])
            ->and(array_column($rows, 'repeat_of', 'n'))->toBe([2 => null, 3 => 2, 4 => null, 5 => 2])
            ->and($lens->summarize($capture)['warnings'])->toHaveCount(1)
            ->and($lens->summarize($capture)['warnings'][0])->toStartWith('Cache key a read 3 times from ')->toContain('CacheLensTest.php:')
            ->and($lens->summarize($capture)['warnings'][0])->toEndWith('Cache::memo() or once().');

        $lens->configure(['repeat_threshold' => 4]);

        expect($lens->summarize($capture)['warnings'])->toBe([]);
    });
});
