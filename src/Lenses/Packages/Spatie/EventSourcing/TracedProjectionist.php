<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Spatie\EventSourcing;

use Exception;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\ThrowableChain;
use Illuminate\Support\Collection;
use Spatie\EventSourcing\EventHandlers\EventHandler;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\Events\EventHandlerFailedHandlingEvent;
use Spatie\EventSourcing\Projectionist;
use Spatie\EventSourcing\StoredEvents\StoredEvent;

/**
 * The projectionist, with every projector and reactor it calls timed. While
 * nothing is recorded it does exactly what the package's own does.
 *
 * ponytail: handling repeats Projectionist's private steps (weights, the
 * isProjecting flag, catching exceptions); keep it in step with the package.
 *
 * @internal
 */
final class TracedProjectionist extends Projectionist
{
    private EventSourcingLens $lens;

    private Eyepiece $eyepiece;

    /**
     * The traced projectionist with the handlers and state of the package's own.
     */
    public static function wrap(Projectionist $projectionist, EventSourcingLens $lens, Eyepiece $eyepiece): self
    {
        if ($projectionist instanceof self) {
            return $projectionist;
        }

        $traced = new self(['catch_exceptions' => $projectionist->catchExceptions]);
        $traced->projectors = $projectionist->projectors;
        $traced->reactors = $projectionist->reactors;
        $traced->pendingProjectors = $projectionist->pendingProjectors;
        $traced->pendingReactors = $projectionist->pendingReactors;
        $traced->isProjecting = $projectionist->isProjecting;
        $traced->isReplaying = $projectionist->isReplaying;
        $traced->lens = $lens;
        $traced->eyepiece = $eyepiece;

        return $traced;
    }

    public function handleWithSyncEventHandlers(StoredEvent $storedEvent): void
    {
        if (! $this->eyepiece->recording()) {
            parent::handleWithSyncEventHandlers($storedEvent);

            return;
        }

        // Resolves handlers registered by class name.
        $this->allEventHandlers();

        $this->lens->queued($storedEvent, $this->projectors->merge($this->reactors)->forEvent($storedEvent)->asyncEventHandlers($storedEvent)->isNotEmpty());
        $this->run($storedEvent, false, $this->projectors->forEvent($storedEvent)->syncEventHandlers($storedEvent), $this->reactors->forEvent($storedEvent)->syncEventHandlers($storedEvent));
    }

    public function handle(StoredEvent $storedEvent): void
    {
        if (! $this->eyepiece->recording()) {
            parent::handle($storedEvent);

            return;
        }

        $this->allEventHandlers();

        $this->run($storedEvent, true, $this->projectors->forEvent($storedEvent)->asyncEventHandlers($storedEvent), $this->reactors->forEvent($storedEvent)->asyncEventHandlers($storedEvent));
    }

    /**
     * The projectors, then the reactors, in one span for the event.
     *
     * @param  Collection<int, EventHandler>  $projectors
     * @param  Collection<int, EventHandler>  $reactors
     */
    private function run(StoredEvent $storedEvent, bool $queued, Collection $projectors, Collection $reactors): void
    {
        if ($projectors->isEmpty() && $reactors->isEmpty()) {
            return;
        }

        $span = $this->eyepiece->span(class_basename($storedEvent->event_class), 'event_sourcing')->start([
            'event' => $storedEvent->event_class,
            'aggregate_uuid' => $storedEvent->aggregate_uuid,
        ]);

        try {
            $this->isProjecting = true;
            $projectors->sortBy(fn (EventHandler $handler): int => $handler->getWeight($storedEvent))->each(fn (EventHandler $handler) => $this->call($handler, $storedEvent, $queued));
            $this->isProjecting = false;

            $reactors->sortBy(fn (EventHandler $handler): int => $handler->getWeight($storedEvent))->each(fn (EventHandler $handler) => $this->call($handler, $storedEvent, $queued));
        } finally {
            $this->isProjecting = false;
            $span->stop();
        }
    }

    /**
     * One handler, refreshed from the container as the package does, timed.
     */
    private function call(EventHandler $handler, StoredEvent $storedEvent, bool $queued): void
    {
        $handler = app($handler::class);
        $type = $handler instanceof Projector ? 'projector' : 'reactor';
        $span = $this->eyepiece->span(class_basename($handler), 'event_sourcing.handler')->start(['handler' => $handler::class, 'type' => $type]);
        $exception = null;

        try {
            $handler->handle($storedEvent);
        } catch (Exception $caught) {
            $exception = $caught;

            if (! $this->catchExceptions) {
                throw $caught;
            }

            $handler->handleException($caught);

            event(new EventHandlerFailedHandlingEvent($handler, $storedEvent, $caught));
        } finally {
            $span->stop(['status' => $exception === null ? 'ok' : 'failed']);

            $this->lens->handled($storedEvent, $queued, [
                'handler' => $handler::class,
                'type' => $type,
                'mode' => $queued ? 'queued' : 'sync',
                'status' => $exception === null ? 'ok' : 'failed',
                'duration' => $span->duration(),
                'exception' => $exception === null ? null : ThrowableChain::describe($exception),
            ]);
        }
    }
}
