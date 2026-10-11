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

    /** @var array<string, bool> */
    private array $ignoredEvents = [];

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
        $this->ignoredEvents = [];
    }

    public function boot(Application $app): void
    {
        $this->events = $app->make(Dispatcher::class);

        $this->eyepiece->listen('*', $this->onEvent(...));
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    private function onEvent(string $event, array $payload): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        if ($this->ignored($event)) {
            return;
        }

        $object = $payload[0] ?? null;

        $this->eyepiece->push('events', [
            'event' => $event,
            'payload' => $this->payload($event, $payload),
            'listeners' => $this->listeners($event),
            'broadcast' => $object instanceof ShouldBroadcast,
        ]);
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('events');

        if ($rows === []) {
            return Summary::make();
        }

        /** @var array<string, true> $unhandled event names nobody listened to */
        $unhandled = [];

        foreach ($rows as $row) {
            if ($row['listeners'] === []) {
                $unhandled[$row['event']] = true;
            }
        }

        return Summary::make()
            ->total('events', count($rows), 'events')
            ->total('events_without_listeners', count($unhandled), 'without listeners');
    }

    private function ignored(string $event): bool
    {
        // Every event of the application passes through here; decide once per event name.
        return $this->ignoredEvents[$event] ??= $this->matchesIgnore($event);
    }

    private function matchesIgnore(string $event): bool
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

        // An event object dispatched under its own class name: its properties are the payload.
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
        // getRawListeners() is not on the Dispatcher contract; a custom dispatcher may lack it.
        if (! method_exists($this->events, 'getRawListeners')) {
            return [];
        }

        $listeners = [];

        foreach ($this->events->getRawListeners()[$event] ?? [] as $listener) {
            $name = $this->describeListener($listener);

            // Monocle listens to everything itself; that is not the application's listener.
            if (str_starts_with($name, 'ExeQue\\Monocle\\')) {
                continue;
            }

            $listeners[] = $name;
        }

        return $listeners;
    }

    /**
     * class@method for an [object or class, method] pair, the class for an
     * invokable, 'Closure' for a closure.
     */
    private function describeListener(mixed $listener): string
    {
        if (is_string($listener)) {
            return $listener;
        }

        if (is_array($listener)) {
            $class = is_object($listener[0]) ? $listener[0]::class : (string) $listener[0];

            return $class.'@'.($listener[1] ?? 'handle');
        }

        if ($listener instanceof Closure) {
            return 'Closure';
        }

        if (is_object($listener)) {
            return $listener::class;
        }

        return 'listener';
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.events')
            ->groupBy(['event' => 'by event'])
            ->format(['payload' => 'fields']);
    }
}
