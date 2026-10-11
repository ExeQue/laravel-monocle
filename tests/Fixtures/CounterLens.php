<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;

/**
 * Minimal custom lens used to prove the plugin API: pushes a row per
 * CounterEvent, honours options and contributes to the summary.
 */
final class CounterLens implements Configurable, Finalizable, Lens, Resettable, Summarizable
{
    public static int $booted = 0;

    public static int $finalized = 0;

    public static int $reset = 0;

    /** @var array<string, mixed> */
    public static array $options = [];

    private Eyepiece $eyepiece;

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

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        self::$booted++;
        $this->eyepiece = $eyepiece;

        $app->make('events')->listen(CounterEvent::class, function (CounterEvent $event): void {
            $this->eyepiece->push('counter', ['value' => $event->value]);
        });
    }

    public function finalize(Capture $capture): void
    {
        self::$finalized++;
        $capture->sections['counter_meta'] = ['finalized' => true];
    }

    public function reset(): void
    {
        self::$reset++;
    }

    public function summarize(Capture $capture): Summary
    {
        $count = count($capture->rows('counter'));

        return new Summary(
            totals: ['counter_events' => $count],
            warnings: $count > 2 ? ['Too many counter events.'] : [],
        );
    }

    public static function clear(): void
    {
        self::$booted = 0;
        self::$finalized = 0;
        self::$reset = 0;
        self::$options = [];
    }
}
