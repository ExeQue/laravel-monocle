<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use DateTimeInterface;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Queue\SyncDispatchPipe;
use ExeQue\Monocle\Support\ThrowableChain;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Queue;
use ReflectionProperty;
use Throwable;

/**
 * Both sides of the queue. Dispatch side: records every queued job in the
 * `jobs` section and injects parent/trace ids into the payload. Worker side:
 * turns every processed job into its own capture linked to the capture that
 * dispatched it, or into a span when it runs inline inside another capture.
 */
final class QueueLens implements Advises, Lens, Presentable, Resettable, Summarizable
{
    public const string PAYLOAD_KEY = 'monocle';


    private Application $app;

    /** @var array<string, Span> open inline job spans by job id */
    private array $spans = [];

    /** The phase covering a job capture from start to finish. */
    private ?Span $phase = null;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'jobs';
    }

    public function description(): string
    {
        return 'Jobs dispatched by this capture: job class, connection, queue, delay, job_id, batch_id, chain length and whether it ran inline. Each queued job becomes its own capture (type job) with parent_id pointing back here - follow them with monocle-trace. Inline jobs (sync queue, dispatchSync) appear as job spans in the timeline.';
    }

    public function advice(): string
    {
        return 'Look here to see which jobs a request dispatched and to which queue; follow them with monocle-trace.';
    }

    public function boot(Application $app): void
    {
        $this->app = $app;
        $this->registerPayloadHook();
        $this->registerSyncDispatchPipe($app);

        $this->eyepiece->listen(JobQueued::class, $this->onQueued(...));
        $this->eyepiece->listen(JobProcessing::class, $this->onProcessing(...));
        $this->eyepiece->listen(JobProcessed::class, fn (JobProcessed $event) => $this->finish($event->job, 'processed'));
        $this->eyepiece->listen(JobFailed::class, fn (JobFailed $event) => $this->fail($event->job, $event->exception, 'failed'));
        $this->eyepiece->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => $this->fail($event->job, $event->exception, 'exception', finish: true));
    }

    public function reset(): void
    {
        $this->spans = [];
        $this->phase = null;
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('jobs');
        $summary = Summary::make()
            ->total('jobs_dispatched', count($rows), 'dispatched')
            ->total('jobs_inline', count(array_filter($rows, fn (array $row): bool => (bool) ($row['sync'] ?? false))), 'inline');

        if ($capture->type !== CaptureType::Job) {
            return $summary;
        }

        $job = $capture->section('job');

        return $summary
            ->when(isset($job['attempts']))
            ->total('job_attempt', $job['attempts'] ?? 0, 'attempt')
            ->when(isset($job['exception']))
            ->error(sprintf('Job failed: %s - %s', $job['exception']['class'] ?? 'Throwable', $job['exception']['message'] ?? ''));
    }

    private function registerPayloadHook(): void
    {
        Queue::createPayloadUsing(static function (?string $connection, ?string $queue, array $payload): array {
            // Resolved per call: the hook is process-wide while eyepieces are per application.
            $eyepiece = Container::getInstance()->make(Eyepiece::class);
            $capture = $eyepiece->active();

            if ($capture === null || ! $eyepiece->recording()) {
                return [];
            }

            return [self::PAYLOAD_KEY => [
                'parent_id' => $capture->id,
                'trace_id' => $capture->traceId,
            ]];
        });
    }

    /**
     * dispatchSync()/dispatchNow() never touch a queue; a bus pipe is the only
     * hook. Existing pipes are kept.
     */
    private function registerSyncDispatchPipe(Application $app): void
    {
        try {
            $dispatcher = $app->make(BusDispatcher::class);
            $property = new ReflectionProperty($dispatcher, 'pipes');
            $pipes = (array) $property->getValue($dispatcher);

            if (! in_array(SyncDispatchPipe::class, $pipes, true)) {
                $dispatcher->pipeThrough([...$pipes, SyncDispatchPipe::class]);
            }
        } catch (Throwable) {
            // A custom bus dispatcher without pipes: inline dispatches are not timed.
        }
    }

    private function onQueued(JobQueued $event): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $payload = is_string($event->payload) ? (array) json_decode($event->payload, true) : (array) $event->payload;

        $this->eyepiece->push('jobs', [
            'job' => $payload['displayName'] ?? (is_object($event->job) ? $event->job::class : (string) $event->job),
            'connection' => $event->connectionName,
            'queue' => $event->queue,
            'delay' => $event->delay instanceof DateTimeInterface ? $event->delay->format(DATE_ATOM) : $event->delay,
            'job_id' => $payload['uuid'] ?? $event->id,
            'batch_id' => $payload['data']['command'] ?? null ? $this->batchId($event->job) : null,
            'chained' => count($payload['data']['command'] ?? null ? $this->chain($event->job) : []),
            'sync' => $this->isSync($event->connectionName),
        ]);
    }

    private function onProcessing(JobProcessing $event): void
    {
        $job = $event->job;
        $payload = $job->payload();

        if ($this->eyepiece->active() !== null) {
            if (! $this->eyepiece->recording()) {
                return;
            }

            // The sync queue fires no JobQueued event, so the dispatch row is written here.
            $this->eyepiece->push('jobs', [
                'job' => $job->resolveName(),
                'connection' => $event->connectionName,
                'queue' => $job->getQueue(),
                'delay' => null,
                'job_id' => $job->uuid(),
                'batch_id' => $payload['data']['batchId'] ?? null,
                'chained' => count($payload['data']['chained'] ?? []),
                'sync' => true,
            ]);

            $span = $this->eyepiece->span($job->resolveName(), 'job');
            $span->start([
                'connection' => $event->connectionName,
                'queue' => $job->getQueue(),
                'job_id' => $job->uuid(),
                'sync' => true,
            ]);

            $this->spans[$this->jobKey($job)] = $span;

            return;
        }

        $link = (array) ($payload[self::PAYLOAD_KEY] ?? []);

        $this->eyepiece->start(
            CaptureType::Job,
            parentId: $link['parent_id'] ?? null,
            traceId: $link['trace_id'] ?? null,
        );

        $this->eyepiece->describe(name: $job->resolveName());
        $this->eyepiece->set('job', [
            'class' => $job->resolveName(),
            'connection' => $event->connectionName,
            'queue' => $job->getQueue(),
            'job_id' => $job->uuid(),
            'attempts' => $job->attempts(),
            'max_tries' => $job->maxTries(),
            'batch_id' => $payload['data']['batchId'] ?? null,
            'chained_remaining' => count($payload['data']['chained'] ?? []),
            'payload_bytes' => strlen($job->getRawBody()),
        ]);

        $this->phase = $this->eyepiece->span('job', 'phase');
        $this->eyepiece->startSpan($this->phase, origin: false);
        $this->phase->parentId = null;
    }

    private function finish(Job $job, string $status): void
    {
        $key = $this->jobKey($job);

        if (isset($this->spans[$key])) {
            $this->spans[$key]->stop(['status' => $status]);
            unset($this->spans[$key]);

            return;
        }

        $capture = $this->eyepiece->active();

        if ($capture === null || $capture->type !== CaptureType::Job) {
            return;
        }

        if ($capture->status === null || $status !== 'processed') {
            $this->eyepiece->describe(status: $status);
        }

        $this->phase?->stop();
        $this->phase = null;
        $this->eyepiece->finalize();
    }

    private function fail(Job $job, Throwable $exception, string $status, bool $finish = false): void
    {
        $key = $this->jobKey($job);
        $info = ThrowableChain::describe($exception);

        if (isset($this->spans[$key])) {
            $span = $this->spans[$key];

            if (($span->meta['status'] ?? null) !== 'failed') {
                $span->meta(['status' => $status, 'exception' => $info]);
            }

            if ($finish) {
                $span->stop();
                unset($this->spans[$key]);
            }

            return;
        }

        $capture = $this->eyepiece->active();

        if ($capture === null || $capture->type !== CaptureType::Job) {
            return;
        }

        if ($capture->status !== 'failed') {
            $this->eyepiece->describe(status: $status);
            $this->eyepiece->set('job', ['exception' => $info]);
        }

        if ($finish) {
            $this->phase?->stop();
            $this->phase = null;
            $this->eyepiece->finalize();
        }
    }

    private function jobKey(Job $job): string
    {
        return $job->uuid() ?? $job->getJobId() ?? (string) spl_object_id($job);
    }

    private function isSync(?string $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        return $this->app->make('config')->get("queue.connections.{$connection}.driver") === 'sync';
    }

    private function batchId(mixed $job): ?string
    {
        return is_object($job) && method_exists($job, 'batch') ? ($job->batchId ?? null) : null;
    }

    /**
     * @return list<mixed>
     */
    private function chain(mixed $job): array
    {
        return is_object($job) && property_exists($job, 'chained') ? (array) $job->chained : [];
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.jobs')
            ->groupBy(['job' => 'by job', 'queue' => 'by queue']);
    }
}
