<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;

/**
 * Application events with their payload summary and the listeners that
 * handled them. Framework and Monocle internals are ignored by default.
 */
final class EventsLens implements Advises, Configurable, Lens, Presentable, Summarizable
{
    private Dispatcher $events;

    /** @var list<string> */
    private array $ignore = [
        'Illuminate\*',
        'Laravel\*',
        'Livewire\*',
        'eloquent.*',
        'composing:*',
        'creating:*',
        'bootstrapping:*',
        'bootstrapped:*',
        'locale.*',
    ];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'events';
    }

    public function description(): string
    {
        return 'Dispatched application events: event name/class, a summary of the payload, the listeners registered for it (class@method or Closure) and whether it broadcasts. Framework events are ignored; the ignore list is configurable.';
    }

    public function advice(): string
    {
        return 'Look here to see which application events fired and which listeners handled them.';
    }

    public function configure(array $options): void
    {
        $this->ignore = array_values((array) ($options['ignore'] ?? $this->ignore));
    }

    public function boot(Application $app): void
    {
        $this->events = $app->make(Dispatcher::class);

        $this->eyepiece->listen('*', function (string $event, array $payload): void {
            if (! $this->eyepiece->recording() || $this->ignored($event)) {
                return;
            }

            $object = $payload[0] ?? null;

            $this->eyepiece->push('events', [
                'event' => $event,
                'payload' => $this->payload($event, $payload),
                'listeners' => $this->listeners($event),
                'broadcast' => $object instanceof ShouldBroadcast,
            ]);
        });
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('events');

        if ($rows === []) {
            return Summary::make();
        }

        $unhandled = array_values(array_unique(array_column(
            array_filter($rows, fn (array $row): bool => $row['listeners'] === []),
            'event',
        )));

        return Summary::make()
            ->total('events', count($rows), 'events')
            ->total('events_without_listeners', count($unhandled), 'without listeners');
    }

    private function ignored(string $event): bool
    {
        if (str_starts_with($event, 'ExeQue\\Monocle\\')) {
            return true;
        }

        foreach ($this->ignore as $pattern) {
            if (Str::is($pattern, $event)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function payload(string $event, array $payload): array
    {
        $object = $payload[0] ?? null;

        if (is_object($object) && $object::class === $event) {
            return Normalizer::normalize(get_object_vars($object));
        }

        return Normalizer::normalize($payload);
    }

    /**
     * @return list<string>
     */
    private function listeners(string $event): array
    {
        $raw = method_exists($this->events, 'getRawListeners') ? ($this->events->getRawListeners()[$event] ?? []) : [];
        $listeners = [];

        foreach ($raw as $listener) {
            $listeners[] = match (true) {
                is_string($listener) => $listener,
                is_array($listener) => (is_object($listener[0]) ? $listener[0]::class : (string) $listener[0]).'@'.($listener[1] ?? 'handle'),
                $listener instanceof Closure => 'Closure',
                is_object($listener) => $listener::class,
                default => 'listener',
            };
        }

        return array_values(array_filter($listeners, fn (string $listener): bool => ! str_starts_with($listener, 'ExeQue\\Monocle\\')));
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.events')
            ->groupBy(['event' => 'by event'])
            ->format(['payload' => 'fields']);
    }
}
