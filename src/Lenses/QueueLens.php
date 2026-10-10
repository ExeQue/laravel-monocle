<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use DateTimeInterface;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Entry;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\EntryType;
use ExeQue\Monocle\Lenses\Queue\SyncDispatchPipe;
use ExeQue\Monocle\Recorder;
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
 * turns every processed job into its own entry linked to the entry that
 * dispatched it, or into a span when it runs inline inside another entry.
 */
final class QueueLens implements Lens, Resettable, Summarizable
{
    public const string PAYLOAD_KEY = 'monocle';

    private Recorder $recorder;

    private Application $app;

    /** @var array<string, Span> open inline job spans by job id */
    private array $spans = [];

    public function key(): string
    {
        return 'jobs';
    }

    public function description(): string
    {
        return 'Jobs dispatched by this entry: job class, connection, queue, delay, job_id, batch_id, chain length and whether it ran inline. Each queued job becomes its own entry (type job) with parent_id pointing back here - follow them with monocle-trace. Inline jobs (sync queue, dispatchSync) appear as job spans in the timeline.';
    }

    public function boot(Application $app, Recorder $recorder): void
    {
        $this->app = $app;
        $this->recorder = $recorder;

        $this->registerPayloadHook();
        $this->registerSyncDispatchPipe($app);

        $recorder->listen(JobQueued::class, $this->onQueued(...));
        $recorder->listen(JobProcessing::class, $this->onProcessing(...));
        $recorder->listen(JobProcessed::class, fn (JobProcessed $event) => $this->finish($event->job, 'processed'));
        $recorder->listen(JobFailed::class, fn (JobFailed $event) => $this->fail($event->job, $event->exception, 'failed'));
        $recorder->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => $this->fail($event->job, $event->exception, 'exception', finish: true));
    }

    public function reset(): void
    {
        $this->spans = [];
    }

    public function summarize(Entry $entry): array
    {
        $rows = $entry->rows('jobs');
        $totals = [
            'jobs_dispatched' => count($rows),
            'jobs_inline' => count(array_filter($rows, fn (array $row): bool => (bool) ($row['sync'] ?? false))),
        ];
        $warnings = [];

        if ($entry->type === EntryType::Job) {
            $job = $entry->section('job');
            $totals['job_attempt'] = $job['attempts'] ?? null;

            if (isset($job['exception'])) {
                $warnings[] = sprintf('Job failed: %s - %s', $job['exception']['class'] ?? 'Throwable', $job['exception']['message'] ?? '');
            }
        }

        return ['totals' => $totals, 'warnings' => $warnings];
    }

    private function registerPayloadHook(): void
    {
        Queue::createPayloadUsing(static function (?string $connection, ?string $queue, array $payload): array {
            // Resolved per call: the hook is process-wide while recorders are per application.
            $recorder = Container::getInstance()->make(Recorder::class);
            $entry = $recorder->active();

            if ($entry === null || ! $recorder->recording()) {
                return [];
            }

            return [self::PAYLOAD_KEY => [
                'parent_id' => $entry->id,
                'trace_id' => $entry->traceId,
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
        if (! $this->recorder->recording()) {
            return;
        }

        $payload = is_string($event->payload) ? (array) json_decode($event->payload, true) : (array) $event->payload;

        $this->recorder->push('jobs', [
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

        if ($this->recorder->active() !== null) {
            if (! $this->recorder->recording()) {
                return;
            }

            // The sync queue fires no JobQueued event, so the dispatch row is written here.
            $this->recorder->push('jobs', [
                'job' => $job->resolveName(),
                'connection' => $event->connectionName,
                'queue' => $job->getQueue(),
                'delay' => null,
                'job_id' => $job->uuid(),
                'batch_id' => $payload['data']['batchId'] ?? null,
                'chained' => count($payload['data']['chained'] ?? []),
                'sync' => true,
            ]);

            $span = $this->recorder->span($job->resolveName(), 'job');
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

        $this->recorder->start(
            EntryType::Job,
            parentId: $link['parent_id'] ?? null,
            traceId: $link['trace_id'] ?? null,
        );

        $this->recorder->describe(name: $job->resolveName());
        $this->recorder->set('job', [
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
    }

    private function finish(Job $job, string $status): void
    {
        $key = $this->jobKey($job);

        if (isset($this->spans[$key])) {
            $this->spans[$key]->stop(['status' => $status]);
            unset($this->spans[$key]);

            return;
        }

        $entry = $this->recorder->active();

        if ($entry === null || $entry->type !== EntryType::Job) {
            return;
        }

        if ($entry->status === null || $status !== 'processed') {
            $this->recorder->describe(status: $status);
        }

        $this->recorder->finalize();
    }

    private function fail(Job $job, Throwable $exception, string $status, bool $finish = false): void
    {
        $key = $this->jobKey($job);
        $info = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
        ];

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

        $entry = $this->recorder->active();

        if ($entry === null || $entry->type !== EntryType::Job) {
            return;
        }

        if ($entry->status !== 'failed') {
            $this->recorder->describe(status: $status);
            $this->recorder->set('job', ['exception' => $info]);
        }

        if ($finish) {
            $this->recorder->finalize();
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
}
