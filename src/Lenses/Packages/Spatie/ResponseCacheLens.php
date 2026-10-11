<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Spatie;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;
use Spatie\ResponseCache\Events\CacheMissedEvent;
use Spatie\ResponseCache\Events\ClearedResponseCacheEvent;
use Spatie\ResponseCache\Events\ClearingResponseCacheEvent;
use Spatie\ResponseCache\Events\ClearingResponseCacheFailedEvent;
use Spatie\ResponseCache\Events\ResponseCacheHitEvent;

/**
 * spatie/laravel-responsecache: whether the whole response came from the
 * cache. On a hit the controller never ran, so a change to it shows nothing
 * until the cache entry expires or is cleared.
 */
final class ResponseCacheLens implements Advises, Lens, Summarizable
{
    private Eyepiece $eyepiece;

    public function key(): string
    {
        return 'response_cache';
    }

    public function description(): string
    {
        return 'spatie/laravel-responsecache: event (hit, miss, clearing, cleared, clearing_failed), for a hit the age of the cached response in seconds and its tags. On a hit the controller did not run.';
    }

    public function advice(): string
    {
        return 'Look here when a change does not show up: a hit means the whole response came from the cache and the controller did not run.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        $events = [
            ResponseCacheHitEvent::class => fn (object $event): array => ['event' => 'hit', 'age_seconds' => $event->ageInSeconds ?? null, 'tags' => $event->tags ?? null],
            CacheMissedEvent::class => fn (object $event): array => ['event' => 'miss'],
            ClearingResponseCacheEvent::class => fn (object $event): array => ['event' => 'clearing'],
            ClearedResponseCacheEvent::class => fn (object $event): array => ['event' => 'cleared'],
            ClearingResponseCacheFailedEvent::class => fn (object $event): array => ['event' => 'clearing_failed'],
        ];

        foreach ($events as $class => $row) {
            if (! class_exists($class)) {
                continue;
            }

            $eyepiece->listen($class, function (object $event) use ($row): void {
                if ($this->eyepiece->recording()) {
                    $this->eyepiece->push('response_cache', $row($event));
                }
            });
        }
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('response_cache');
        $hit = array_values(array_filter($rows, fn (array $row): bool => $row['event'] === 'hit'))[0] ?? null;

        if ($rows === []) {
            return [];
        }

        return [
            'totals' => ['response_cache' => $hit !== null ? 'hit' : (in_array('miss', array_column($rows, 'event'), true) ? 'miss' : 'cleared')],
            'warnings' => $hit === null ? [] : [sprintf(
                'Served from the response cache%s; the controller did not run. Clear it (php artisan responsecache:clear) to see a change.',
                $hit['age_seconds'] === null ? '' : " ({$hit['age_seconds']} s old)",
            )],
        ];
    }
}
