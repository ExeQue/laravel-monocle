<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Spatie;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
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
final class ResponseCacheLens implements Advises, Lens, Presentable, RequiresPackages, Summarizable
{
    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'response_cache';
    }

    public static function packages(): array
    {
        return ['spatie/laravel-responsecache'];
    }

    public function description(): string
    {
        return 'spatie/laravel-responsecache: event (hit, miss, clearing, cleared, clearing_failed), for a hit the age of the cached response in seconds and its tags. On a hit the controller did not run.';
    }

    public function advice(): string
    {
        return 'Look here when a change does not show up: a hit means the whole response came from the cache and the controller did not run.';
    }

    public function boot(): void
    {
        // Event class => the row it becomes.
        $events = [
            ResponseCacheHitEvent::class => fn (object $event): array => [
                'event' => 'hit',
                'age_seconds' => $event->ageInSeconds ?? null,
                'tags' => $event->tags ?? null,
            ],
            CacheMissedEvent::class => fn (object $event): array => ['event' => 'miss'],
            ClearingResponseCacheEvent::class => fn (object $event): array => ['event' => 'clearing'],
            ClearedResponseCacheEvent::class => fn (object $event): array => ['event' => 'cleared'],
            ClearingResponseCacheFailedEvent::class => fn (object $event): array => ['event' => 'clearing_failed'],
        ];

        foreach ($events as $class => $row) {
            // Event missing from older spatie/laravel-responsecache versions.
            // @codeCoverageIgnoreStart
            if (! class_exists($class)) {
                continue;
            }
            // @codeCoverageIgnoreEnd

            $this->eyepiece->listen($class, function (object $event) use ($row): void {
                if ($this->eyepiece->recording()) {
                    $this->eyepiece->push('response_cache', $row($event));
                }
            });
        }
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('response_cache');

        if ($rows === []) {
            return Summary::make();
        }

        $hits = array_filter($rows, fn (array $row): bool => $row['event'] === 'hit');
        $hit = array_values($hits)[0] ?? null;

        return Summary::make()
            ->total('response_cache', $this->outcome($rows, $hit), 'response cache')
            ->warnings($hit === null ? [] : [$this->hitWarning($hit)]);
    }

    /**
     * A hit wins over a miss; rows with neither only cleared the cache.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $hit
     */
    private function outcome(array $rows, ?array $hit): string
    {
        if ($hit !== null) {
            return 'hit';
        }

        if (in_array('miss', array_column($rows, 'event'), true)) {
            return 'miss';
        }

        return 'cleared';
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    private function hitWarning(array $hit): string
    {
        $age = $hit['age_seconds'] === null ? '' : " ({$hit['age_seconds']} s old)";

        return sprintf(
            'Served from the response cache%s; the controller did not run. Clear it (php artisan responsecache:clear) to see a change.',
            $age,
        );
    }

    public function presentation(): Presentation
    {
        return Presentation::table()->tab('monocle::tabs.response_cache');
    }
}
