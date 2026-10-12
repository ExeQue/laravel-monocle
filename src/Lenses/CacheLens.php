<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Origin;
use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheFlushed;
use Illuminate\Cache\Events\CacheFlushFailed;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Records cache hits, misses, writes and forgets with durations measured from
 * the matching start events.
 */
final class CacheLens implements Advises, Configurable, Lens, Presentable, Resettable, Summarizable
{
    private bool $values = false;

    private int $repeatThreshold = 5;

    private float $repeatMs = 0.0;

    private float $slowMs = 20.0;

    private int $counter = 0;

    /** @var array<string, int> store|key => row number of its first read */
    private array $firstRead = [];

    /** @var array<string, float> store|key => started at (ms) */
    private array $pending = [];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'cache';
    }

    public function description(): string
    {
        return 'Cache operations in order: n (row number), type (hit, miss, write, forget, flush, write_failed, forget_failed, flush_failed), store, key, tags, ttl, value_size_bytes, duration when the store emits start events, and repeat_of (row number of the first read of the same key) on repeated reads. Values themselves only when the lens option values=true, masked when the key matches the input masking pattern.';
    }

    public function advice(): string
    {
        return 'Look here when data is stale or a page is slower than expected: misses, slow calls, repeated reads of the same key and what was written.';
    }

    public function configure(array $options): void
    {
        $this->values = (bool) ($options['values'] ?? $this->values);
        $this->repeatThreshold = (int) ($options['repeat_threshold'] ?? $this->repeatThreshold);
        $this->repeatMs = (float) ($options['repeat_ms'] ?? $this->repeatMs);
        $this->slowMs = (float) ($options['slow_ms'] ?? $this->slowMs);
    }

    public function boot(): void
    {
        // Stores that fire start events get a duration: the time until the matching end event.
        foreach ([RetrievingKey::class, WritingKey::class, ForgettingKey::class] as $start) {
            $this->eyepiece->listen($start, function (CacheEvent $event): void {
                $this->pending[$this->pendingKey($event)] = $this->eyepiece->now();
            });
        }

        $this->eyepiece->listen(CacheHit::class, function (CacheHit $event): void {
            $this->record('hit', $event, ['value' => $event->value]);
        });
        $this->eyepiece->listen(CacheMissed::class, function (CacheMissed $event): void {
            $this->record('miss', $event);
        });
        $this->eyepiece->listen(KeyWritten::class, function (KeyWritten $event): void {
            $this->record('write', $event, ['value' => $event->value, 'ttl' => $event->seconds]);
        });
        $this->eyepiece->listen(KeyWriteFailed::class, function (KeyWriteFailed $event): void {
            $this->record('write_failed', $event, ['ttl' => $event->seconds]);
        });
        $this->eyepiece->listen(KeyForgotten::class, function (KeyForgotten $event): void {
            $this->record('forget', $event);
        });
        $this->eyepiece->listen(KeyForgetFailed::class, function (KeyForgetFailed $event): void {
            $this->record('forget_failed', $event);
        });
        $this->eyepiece->listen(CacheFlushed::class, function (CacheFlushed $event): void {
            $this->recordFlush('flush', $event->storeName);
        });
        $this->eyepiece->listen(CacheFlushFailed::class, function (CacheFlushFailed $event): void {
            $this->recordFlush('flush_failed', $event->storeName);
        });
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->counter = 0;
        $this->firstRead = [];
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('cache');
        $counts = collect($rows)->countBy(fn (array $row): mixed => $row['type'] ?? 'other')->all()
            + ['hit' => 0, 'miss' => 0, 'write' => 0, 'forget' => 0];
        $ms = collect($rows)->sum(fn (array $row): float => (float) ($row['duration'] ?? 0));
        $hottestKeys = collect($rows)->whereNotNull('key')->countBy('key')->sortDesc()->take(5)->all();

        $reads = $counts['hit'] + $counts['miss'];
        $hitRate = round($counts['hit'] / max($reads, 1) * 100, 1);

        return Summary::make()
            ->total('cache_ms', round($ms, 3), 'spent', Unit::Milliseconds)
            ->warnings($this->slowWarnings($rows))
            ->warnings($this->repeatWarnings($rows))
            ->totals([
                'cache_hottest_keys' => $hottestKeys,
            ])
            ->total('cache_hits', $counts['hit'], 'hits')
            ->total('cache_misses', $counts['miss'], 'misses')
            ->total('cache_writes', $counts['write'], 'writes')
            ->total('cache_forgets', $counts['forget'], 'forgets')
            ->when($reads > 0)
            ->total('cache_hit_rate', $hitRate, 'hit rate', Unit::Percent);
    }

    /**
     * Cache calls slow enough to point at the store: a distant or busy server,
     * a large value or a lock that waited.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function slowWarnings(array $rows): array
    {
        return collect($rows)
            ->filter(fn (array $row): bool => (float) ($row['duration'] ?? 0) >= $this->slowMs)
            ->map(fn (array $row): string => sprintf(
                'Slow cache %s (%.1f ms) on %s: %s%s',
                $row['type'] ?? 'call',
                (float) ($row['duration'] ?? 0),
                $this->describeStore($row['store'] ?? null),
                $row['key'] ?? '?',
                $this->where($row['origin'] ?? null),
            ))
            ->values()
            ->all();
    }

    /**
     * 'the "sessions" store (redis)', with the driver when the name does not say it.
     */
    private function describeStore(?string $store): string
    {
        if ($store === null) {
            return 'the default store';
        }

        $driver = config("cache.stores.{$store}.driver");

        if (! is_string($driver)) {
            return "the \"{$store}\" store";
        }

        if ($driver === $store) {
            return "the \"{$store}\" store";
        }

        return "the \"{$store}\" store ({$driver})";
    }

    /**
     * ' from file:line' for a row's origin, or nothing when it has none.
     */
    private function where(mixed $origin): string
    {
        $described = Origin::describe(is_array($origin) ? $origin : null);

        return $described === null ? '' : ' from '.$described;
    }

    /**
     * The same key read again and again in one capture costs a round trip each
     * time; remembering it for the request (Cache::memo(), once()) avoids that.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function repeatWarnings(array $rows): array
    {
        return collect($rows)
            ->filter(fn (array $row): bool => in_array($row['type'] ?? null, ['hit', 'miss'], true) && isset($row['key']))
            ->groupBy(fn (array $row): string => ($row['store'] ?? '').'|'.$row['key'])
            // The reads of each store|key, with the origin of the first.
            ->map(fn (Collection $reads): array => [
                'key' => $reads->first()['key'],
                'count' => $reads->count(),
                'ms' => $reads->sum(fn (array $row): float => (float) ($row['duration'] ?? 0)),
                'origin' => $reads->first()['origin'] ?? null,
            ])
            ->filter(fn (array $read): bool => $read['count'] >= $this->repeatThreshold
                && $read['ms'] >= $this->repeatMs
                // Repeated reads inside vendor code are not the app's to fix.
                && ! Origin::fromLibrary(is_array($read['origin']) ? $read['origin'] : null))
            ->map(fn (array $read): string => sprintf(
                'Cache key %s read %d times (%.1f ms)%s; remember it for the request with Cache::memo() or once().',
                $read['key'],
                $read['count'],
                $read['ms'],
                $this->where($read['origin']),
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(string $type, CacheEvent $event, array $extra = []): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $pendingKey = $this->pendingKey($event);
        $startedAt = $this->pending[$pendingKey] ?? null;
        unset($this->pending[$pendingKey]);

        $elapsed = $startedAt === null ? null : $this->eyepiece->now() - $startedAt;

        $row = [
            'n' => ++$this->counter,
            'type' => $type,
            'store' => $event->storeName,
            'key' => $event->key,
            'tags' => $event->tags,
            'duration' => $elapsed === null ? null : round($elapsed, 3),
            'slow' => $elapsed !== null && $elapsed >= $this->slowMs,
            'ttl' => $extra['ttl'] ?? null,
        ];

        if (array_key_exists('value', $extra)) {
            $row['value_size_bytes'] = $this->size($extra['value']);

            if ($this->values) {
                // A key that matches the input masking pattern, such as "api_token", has its value masked.
                $row['value'] = $this->eyepiece->masker(self::class)->inputs([$event->key => $extra['value']])[$event->key];
            }
        }

        // Reads point back at the first read of the same key, so repeats are easy to spot.
        if (in_array($type, ['hit', 'miss'], true)) {
            $row['repeat_of'] = $this->firstRead[$pendingKey] ?? null;
            $this->firstRead[$pendingKey] ??= $row['n'];
        }

        $this->eyepiece->push('cache', $row);
    }

    private function recordFlush(string $type, ?string $store): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->eyepiece->push('cache', [
            'type' => $type,
            'store' => $store,
            'key' => null,
            'duration' => null,
        ]);
    }

    private function pendingKey(CacheEvent $event): string
    {
        return ($event->storeName ?? '').'|'.$event->key;
    }

    private function size(mixed $value): ?int
    {
        try {
            return strlen($this->eyepiece->outside(fn (): string => serialize($value)));
        } catch (Throwable) {
            return null;
        }
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.cache')
            ->groupBy(['key' => 'by key', 'type' => 'by operation', 'store' => 'by store'])
            ->detailsOnly(['slow'])
            ->format([
                'type' => ['highlight', ['miss', 'write_failed', 'forget_failed', 'flush_failed'], 'warn'],
                'repeat_of' => ['reference', 'repeat of #'],
            ])
            ->highlight(['repeat_of' => 'warn', 'slow' => 'warn']);
    }
}
