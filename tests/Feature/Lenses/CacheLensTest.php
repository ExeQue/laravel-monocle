<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\EntryType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\CacheLens;
use Illuminate\Support\Facades\Cache;

covers(CacheLens::class);

describe(CacheLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(EntryType::Request);
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
});
