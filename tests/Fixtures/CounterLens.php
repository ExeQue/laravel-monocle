<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use ExeQue\Monocle\Contracts\Configurable;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\SummarizesEntry;
use ExeQue\Monocle\Data\Entry;
use ExeQue\Monocle\Recorder;
use Illuminate\Contracts\Foundation\Application;

/**
 * Minimal custom lens used to prove the plugin API: pushes a row per
 * CounterEvent, honours options and contributes to the summary.
 */
final class CounterLens implements Configurable, Lens, SummarizesEntry
{
    public static int $booted = 0;

    public static int $finalized = 0;

    public static int $reset = 0;

    /** @var array<string, mixed> */
    public static array $options = [];

    private Recorder $recorder;

    public function key(): string
    {
        return 'counter';
    }

    public function description(): string
    {
        return 'Counts CounterEvent dispatches.';
    }

    public function configure(array $options): void
    {
        self::$options = $options;
    }

    public function boot(Application $app, Recorder $recorder): void
    {
        self::$booted++;
        $this->recorder = $recorder;

        $app->make('events')->listen(CounterEvent::class, function (CounterEvent $event): void {
            $this->recorder->push('counter', ['value' => $event->value]);
        });
    }

    public function finalize(Entry $entry): void
    {
        self::$finalized++;
        $entry->sections['counter_meta'] = ['finalized' => true];
    }

    public function reset(): void
    {
        self::$reset++;
    }

    public function summarize(Entry $entry): array
    {
        $count = count($entry->rows('counter'));

        return [
            'totals' => ['counter_events' => $count],
            'warnings' => $count > 2 ? ['Too many counter events.'] : [],
        ];
    }

    public static function clear(): void
    {
        self::$booted = 0;
        self::$finalized = 0;
        self::$reset = 0;
        self::$options = [];
    }
}
