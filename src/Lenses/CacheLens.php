<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheFlushed;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Records cache hits, misses, writes and forgets with durations measured from
 * the matching start events.
 */
final class CacheLens implements Configurable, Lens, Resettable, Summarizable
{
    private Eyepiece $eyepiece;

    private bool $values = false;

    /** @var array<string, float> store|key => started at (ms) */
    private array $pending = [];

    public function key(): string
    {
        return 'cache';
    }

    public function description(): string
    {
        return 'Cache operations: type (hit, miss, write, forget, flush, *_failed), key, store, tags, ttl, value_size_bytes and duration when the store emits start events. Values themselves only when the lens option values=true.';
    }

    public function configure(array $options): void
    {
        $this->values = (bool) ($options['values'] ?? $this->values);
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        foreach ([RetrievingKey::class, WritingKey::class, ForgettingKey::class] as $start) {
            $eyepiece->listen($start, function (CacheEvent $event): void {
                $this->pending[$this->pendingKey($event)] = $this->eyepiece->now();
            });
        }

        $eyepiece->listen(CacheHit::class, fn (CacheHit $event) => $this->record('hit', $event, ['value' => $event->value]));
        $eyepiece->listen(CacheMissed::class, fn (CacheMissed $event) => $this->record('miss', $event));
        $eyepiece->listen(KeyWritten::class, fn (KeyWritten $event) => $this->record('write', $event, ['value' => $event->value, 'ttl' => $event->seconds]));
        $eyepiece->listen(KeyWriteFailed::class, fn (KeyWriteFailed $event) => $this->record('write_failed', $event, ['ttl' => $event->seconds]));
        $eyepiece->listen(KeyForgotten::class, fn (KeyForgotten $event) => $this->record('forget', $event));
        $eyepiece->listen(KeyForgetFailed::class, fn (KeyForgetFailed $event) => $this->record('forget_failed', $event));
        $eyepiece->listen(CacheFlushed::class, function (CacheFlushed $event): void {
            if ($this->eyepiece->recording()) {
                $this->eyepiece->push('cache', ['type' => 'flush', 'key' => null, 'store' => $event->storeName, 'duration' => null]);
            }
        });
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('cache');
        $counts = ['hit' => 0, 'miss' => 0, 'write' => 0, 'forget' => 0];
        $ms = 0.0;
        $keys = [];

        foreach ($rows as $row) {
            $type = $row['type'] ?? 'other';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            $ms += (float) ($row['duration'] ?? 0);

            if (isset($row['key'])) {
                $keys[$row['key']] = ($keys[$row['key']] ?? 0) + 1;
            }
        }

        arsort($keys);
        $reads = $counts['hit'] + $counts['miss'];

        return [
            'totals' => [
                'cache_hits' => $counts['hit'],
                'cache_misses' => $counts['miss'],
                'cache_hit_rate' => $reads === 0 ? null : round($counts['hit'] / $reads * 100, 1),
                'cache_writes' => $counts['write'],
                'cache_forgets' => $counts['forget'],
                'cache_ms' => round($ms, 3),
                'cache_hottest_keys' => array_slice($keys, 0, 5, true),
            ],
        ];
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

        $row = [
            'type' => $type,
            'key' => $event->key,
            'store' => $event->storeName,
            'tags' => $event->tags,
            'duration' => $startedAt === null ? null : round($this->eyepiece->now() - $startedAt, 3),
            'ttl' => $extra['ttl'] ?? null,
        ];

        if (array_key_exists('value', $extra)) {
            $row['value_size_bytes'] = $this->size($extra['value']);

            if ($this->values) {
                $row['value'] = $extra['value'];
            }
        }

        $this->eyepiece->push('cache', $row);
    }

    private function pendingKey(CacheEvent $event): string
    {
        return ($event->storeName ?? '').'|'.$event->key;
    }

    private function size(mixed $value): ?int
    {
        try {
            return strlen(serialize($value));
        } catch (Throwable) {
            return null;
        }
    }
}
