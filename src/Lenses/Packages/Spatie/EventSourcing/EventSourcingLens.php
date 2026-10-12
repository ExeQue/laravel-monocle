<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Spatie\EventSourcing;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\ProvidesSections;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Spatie\EventSourcing\Projectionist;
use Spatie\EventSourcing\StoredEvents\EventSubscriber;
use Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository;
use Spatie\EventSourcing\StoredEvents\StoredEvent;

/**
 * spatie/laravel-event-sourcing: the events stored, the projectors and
 * reactors that handled them, and the aggregate roots retrieved and
 * persisted. The package fires no events around its handlers, so the
 * projectionist and the stored event repository are wrapped.
 */
final class EventSourcingLens implements Advises, Configurable, Finalizable, Lens, Presentable, ProvidesSections, RequiresPackages, Resettable, Summarizable
{
    /** Warn when an aggregate is rebuilt from at least this many events without a snapshot. */
    private int $snapshotThreshold = 100;

    /** @var array<string, array<string, mixed>> Stored event id => its row, finished at finalize. */
    private array $events = [];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'event_sourcing';
    }

    public function sections(): array
    {
        return ['aggregates'];
    }

    public static function packages(): array
    {
        return ['spatie/laravel-event-sourcing'];
    }

    public function description(): string
    {
        return 'spatie/laravel-event-sourcing: one row per stored event the capture stored or handled: event class, stored_event_id, aggregate_uuid, aggregate_version, stored (true when this capture stored it), queued (true when handlers run on the queue), properties (masked like input) and handlers (each projector or reactor that handled it: handler, type, mode sync or queued, status ok or failed, duration and the exception) and duration (the time of its handlers together). Each handled event is an event_sourcing span with an event_sourcing.handler span per handler. Section aggregates has one row per aggregate root retrieved or persisted: action, aggregate class, uuid, events (replayed or stored), snapshot (true when it was rebuilt from a snapshot) and duration.';
    }

    public function advice(): string
    {
        return 'Look here for event sourcing: which events were stored, which projectors and reactors handled them and how long they took, and aggregates rebuilt from many events.';
    }

    public function configure(array $options): void
    {
        $this->snapshotThreshold = (int) ($options['snapshot_threshold'] ?? $this->snapshotThreshold);
    }

    public function boot(Application $app): void
    {
        $app->extend(Projectionist::class, fn (Projectionist $projectionist): Projectionist => TracedProjectionist::wrap($projectionist, $this, $this->eyepiece));

        // Aggregate roots and the event subscriber resolve the configured class itself, not the interface.
        $repository = (string) config('event-sourcing.stored_event_repository');

        foreach (array_unique([StoredEventRepository::class, $repository]) as $abstract) {
            $app->extend($abstract, fn (StoredEventRepository $inner): StoredEventRepository => $inner instanceof TracedStoredEventRepository ? $inner : new TracedStoredEventRepository($inner, $this, $this->eyepiece));
        }

        // The subscriber that stores events fired on their own took its repository when it was made.
        $app->extend(EventSubscriber::class, fn (): EventSubscriber => new EventSubscriber($repository));

        // The facade keeps the projectionist it resolved first.
        Facade::clearResolvedInstance('event-sourcing');
    }

    /**
     * A stored event this capture wrote.
     */
    public function stored(StoredEvent $storedEvent): void
    {
        $this->row($storedEvent)['stored'] = true;
    }

    /**
     * A projector or reactor that handled a stored event.
     *
     * @param  array{handler: string, type: string, mode: string, status: string, duration: float|null, exception: array<string, mixed>|null}  $handler
     */
    public function handled(StoredEvent $storedEvent, bool $queued, array $handler): void
    {
        $row = &$this->row($storedEvent);
        $row['queued'] = $row['queued'] || $queued;
        $row['handlers'][] = $handler;
    }

    /**
     * Whether some of the event's handlers run on the queue.
     */
    public function queued(StoredEvent $storedEvent, bool $queued): void
    {
        $row = &$this->row($storedEvent);
        $row['queued'] = $row['queued'] || $queued;
    }

    /**
     * An aggregate root retrieved or persisted.
     *
     * @param  array<string, mixed>  $row
     */
    public function aggregate(array $row): void
    {
        $this->eyepiece->push('aggregates', $row);
    }

    public function finalize(Capture $capture): void
    {
        foreach ($this->events as $row) {
            // The time its handlers took, together.
            $durations = array_filter(array_column($row['handlers'], 'duration'), is_numeric(...));
            $this->eyepiece->push('event_sourcing', [...$row, 'duration' => $durations === [] ? null : round(array_sum($durations), 3)], origin: false);
        }

        $this->events = [];
    }

    public function reset(): void
    {
        $this->events = [];
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = collect($capture->rows('event_sourcing'));
        $aggregates = collect($capture->rows('aggregates'));

        if ($rows->isEmpty() && $aggregates->isEmpty()) {
            return Summary::make();
        }

        $handlers = $rows->flatMap(fn (array $row): array => collect($row['handlers'] ?? [])->map(fn (array $handler): array => [...$handler, 'event' => $row['event']])->all());

        $errors = $handlers->where('status', 'failed')->take(5)->map(fn (array $handler): string => sprintf(
            '%s %s failed handling %s: %s',
            ucfirst((string) $handler['type']),
            $handler['handler'],
            $handler['event'],
            $handler['exception']['message'] ?? '',
        ));

        $warnings = $aggregates
            ->filter(fn (array $row): bool => $row['action'] === 'retrieved' && ! $row['snapshot'] && $row['events'] >= $this->snapshotThreshold)
            ->map(fn (array $row): string => sprintf('Aggregate %s (%s) was rebuilt from %d events without a snapshot.', $row['aggregate'] ?? 'root', $row['uuid'], $row['events']));

        return Summary::make()
            ->total('event_sourcing_ms', round((float) $handlers->sum('duration'), 3), 'in handlers', Unit::Milliseconds)
            ->total('event_sourcing_stored', $rows->where('stored', true)->count(), 'stored')
            ->total('event_sourcing_handled', $handlers->count(), 'handler calls')
            ->total('event_sourcing_aggregates', $aggregates->count(), 'aggregates')
            ->warnings($warnings->values())
            ->errors($errors->values());
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.event_sourcing')
            ->label('Event sourcing')
            ->columns(['event', 'aggregate_uuid', 'aggregate_version', 'stored', 'queued', 'duration'])
            ->detailsOnly(['stored_event_id', 'properties', 'handlers'])
            ->details('monocle::details.event-sourcing')
            ->format(['stored' => 'bool', 'queued' => 'bool'])
            ->groupBy(['event' => 'by event'])
            ->includes([
                'aggregates' => Presentation::table()
                    ->label('Aggregates')
                    ->columns(['action', 'aggregate', 'uuid', 'events', 'snapshot', 'duration'])
                    ->format(['snapshot' => 'bool'])
                    ->groupBy(['aggregate' => 'by aggregate']),
            ]);
    }

    /**
     * The row of a stored event, made the first time it is seen.
     *
     * @return array<string, mixed>
     */
    private function &row(StoredEvent $storedEvent): array
    {
        $key = (string) ($storedEvent->id ?? spl_object_id($storedEvent));

        $this->events[$key] ??= [
            'event' => $storedEvent->event_class,
            'stored_event_id' => $storedEvent->id,
            'aggregate_uuid' => $storedEvent->aggregate_uuid !== '' ? $storedEvent->aggregate_uuid : null,
            'aggregate_version' => $storedEvent->aggregate_version,
            'stored' => false,
            'queued' => false,
            'properties' => $this->properties($storedEvent),
            'handlers' => [],
            'time' => $this->eyepiece->now(),
            'origin' => $this->eyepiece->origin(),
        ];

        return $this->events[$key];
    }

    /**
     * The event's properties as stored, masked like input.
     *
     * @return array<array-key, mixed>
     */
    private function properties(StoredEvent $storedEvent): array
    {
        $properties = is_string($storedEvent->event_properties) ? json_decode($storedEvent->event_properties, true) : $storedEvent->event_properties;

        return $this->eyepiece->masker(self::class)->inputs(Normalizer::normalize((array) $properties));
    }
}
