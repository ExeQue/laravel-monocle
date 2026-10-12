<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Spatie\EventSourcing;

use Closure;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Support\LazyCollection;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;
use Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use Spatie\EventSourcing\StoredEvents\StoredEvent;

/**
 * The stored event repository, recording the events stored and the
 * aggregate roots retrieved and persisted through it. Everything else is
 * passed on as it is.
 *
 * @internal
 */
final readonly class TracedStoredEventRepository implements StoredEventRepository
{
    public function __construct(
        private StoredEventRepository $inner,
        private EventSourcingLens $lens,
        private Eyepiece $eyepiece,
    ) {
    }

    public function persist(ShouldBeStored $event, ?string $uuid = null): StoredEvent
    {
        $storedEvent = $this->inner->persist($event, $uuid);
        $this->record(fn () => $this->lens->stored($storedEvent));

        return $storedEvent;
    }

    public function persistMany(array $events, ?string $uuid = null): LazyCollection
    {
        if (! $this->eyepiece->recording()) {
            return $this->inner->persistMany($events, $uuid);
        }

        $aggregate = $this->aggregate();
        $started = $this->eyepiece->now();
        // Stored right away, so the collection is read once here and kept.
        $storedEvents = $this->inner->persistMany($events, $uuid)->collect();

        $this->record(function () use ($storedEvents, $aggregate, $uuid, $started): void {
            $storedEvents->each(fn (StoredEvent $storedEvent) => $this->lens->stored($storedEvent));

            if ($aggregate !== null) {
                $this->lens->aggregate(['action' => 'persisted', 'aggregate' => $aggregate, 'uuid' => $uuid, 'events' => $storedEvents->count(), 'snapshot' => false, 'time' => $started, 'duration' => round($this->eyepiece->now() - $started, 3)]);
            }
        });

        return LazyCollection::make($storedEvents->all());
    }

    public function retrieveAll(?string $uuid = null): LazyCollection
    {
        return $this->replayed($this->inner->retrieveAll($uuid), $uuid, false);
    }

    public function retrieveAllAfterVersion(int $aggregateVersion, string $aggregateUuid): LazyCollection
    {
        // Only an aggregate rebuilt from a snapshot asks for the events after its version.
        return $this->replayed($this->inner->retrieveAllAfterVersion($aggregateVersion, $aggregateUuid), $aggregateUuid, true);
    }

    public function find(int $id): StoredEvent
    {
        return $this->inner->find($id);
    }

    public function retrieveAllStartingFrom(int $startingFrom, ?string $uuid = null, array $events = []): LazyCollection
    {
        return $this->inner->retrieveAllStartingFrom($startingFrom, $uuid, $events);
    }

    public function runForAllStartingFrom(int $startingFrom, callable|Closure $function, int $chunkSize = 1000, ?string $uuid = null, array $events = []): bool
    {
        return $this->inner->runForAllStartingFrom($startingFrom, $function, $chunkSize, $uuid, $events);
    }

    public function countAllStartingFrom(int $startingFrom, ?string $uuid = null, array $events = []): int
    {
        return $this->inner->countAllStartingFrom($startingFrom, $uuid, $events);
    }

    public function update(StoredEvent $storedEvent): StoredEvent
    {
        return $this->inner->update($storedEvent);
    }

    public function getLatestAggregateVersion(string $aggregateUuid): int
    {
        return $this->inner->getLatestAggregateVersion($aggregateUuid);
    }

    /**
     * Methods a repository of your own adds are passed on.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    /**
     * The events an aggregate root is rebuilt from, counted and timed as it
     * reads them. Read by anything else, they are passed on as they are.
     *
     * @param  LazyCollection<int, StoredEvent>  $events
     * @return LazyCollection<int, StoredEvent>
     */
    private function replayed(LazyCollection $events, ?string $uuid, bool $snapshot): LazyCollection
    {
        $aggregate = $this->eyepiece->recording() ? $this->aggregate() : null;

        if ($aggregate === null) {
            return $events;
        }

        return LazyCollection::make(function () use ($events, $aggregate, $uuid, $snapshot) {
            $started = $this->eyepiece->now();
            $count = 0;

            foreach ($events as $key => $event) {
                $count++;

                yield $key => $event;
            }

            $this->record(fn () => $this->lens->aggregate(['action' => 'retrieved', 'aggregate' => $aggregate, 'uuid' => $uuid, 'events' => $count, 'snapshot' => $snapshot, 'time' => $started, 'duration' => round($this->eyepiece->now() - $started, 3)]));
        });
    }

    /**
     * The aggregate root asking, from the call stack.
     */
    private function aggregate(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            if (($frame['object'] ?? null) instanceof AggregateRoot) {
                return $frame['object']::class;
            }
        }

        return null;
    }

    /**
     * Monocle's own work, timed as the lens's and kept away from the application when it fails.
     */
    private function record(Closure $work): void
    {
        if ($this->eyepiece->recording()) {
            $this->eyepiece->wrap($work)();
        }
    }
}
