<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use Carbon\CarbonImmutable;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Queue\SyncDispatchPipe;
use ExeQue\Monocle\Lenses\QueueLens;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\Fixtures\Jobs\CountdownJob;
use Tests\Fixtures\Jobs\FailingJob;
use Tests\Fixtures\Jobs\PlainCommand;
use Tests\Fixtures\Jobs\RecordingJob;

covers(QueueLens::class, SyncDispatchPipe::class, CaptureReader::class);

describe(QueueLens::class, function (): void {
    beforeEach(function (): void {
        RecordingJob::$handled = 0;

        Schema::create('jobs', function ($table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        config([
            'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'connection' => 'testing'],
            'queue.failed.driver' => 'null',
        ]);

        Route::get('/dispatch', function () {
            RecordingJob::dispatch('queued')->onConnection('database');

            return 'dispatched';
        });
        Route::get('/dispatch-failing', function () {
            FailingJob::dispatch()->onConnection('database');

            return 'dispatched';
        });
        Route::get('/inline', function () {
            RecordingJob::dispatch('inline')->onConnection('sync');

            return 'inline';
        });
        Route::get('/now', function () {
            RecordingJob::dispatchSync('now');

            return 'now';
        });
        Route::get('/plain', function () {
            PlainCommand::dispatchSync();

            return 'plain';
        });
    });

    $captures = fn (?string $type = null): array => app(CaptureReader::class)->list(Filter::fromArray(['type' => $type, 'limit' => 50]))['rows'];

    it('records dispatched jobs and links the worker-side capture to the request', function () use ($captures): void {
        $this->get('/dispatch')->assertOk();

        $request = latestCapture();
        $dispatched = $request->rows('jobs');

        expect($dispatched)->toHaveCount(1)
            ->and($dispatched[0]['job'])->toBe(RecordingJob::class)
            ->and($dispatched[0]['connection'])->toBe('database')
            ->and($dispatched[0]['sync'])->toBeFalse()
            ->and($dispatched[0]['job_id'])->toBeString();

        Artisan::call('queue:work', ['--once' => true, 'connection' => 'database']);

        $job = latestCapture();

        expect(RecordingJob::$handled)->toBe(1)
            ->and($job->type)->toBe(CaptureType::Job)
            ->and($job->name)->toBe(RecordingJob::class)
            ->and($job->status)->toBe('processed')
            ->and($job->parentId)->toBe($request->id)
            ->and($job->traceId)->toBe($request->id)
            ->and($job->section('job')['job_id'])->toBe($dispatched[0]['job_id'])
            ->and($job->section('job')['attempts'])->toBe(1)
            ->and($job->rows('cache'))->not->toBeEmpty()
            ->and($job->spansOfKind('phase')[0]->name)->toBe('job')
            ->and($job->spansOfKind('phase')[0]->isOpen())->toBeFalse()
            ->and($job->health['open_spans_at_finalize'])->toBe(0)
            ->and($captures('job'))->toHaveCount(1)
            ->and($captures('request'))->toHaveCount(1);

        $trace = app(CaptureReader::class)->trace($request->id);

        expect(array_column($trace, 'depth'))->toBe([0, 1])
            ->and($trace[1]['offset_ms'])->toBeGreaterThan(0);
    });

    it('follows a trace through jobs that dispatch jobs, however deep', function (): void {
        Route::get('/countdown', function () {
            CountdownJob::dispatch(3)->onConnection('database');

            return 'dispatched';
        });

        $this->get('/countdown')->assertOk();
        $ids = [latestCapture()->id];

        foreach (range(1, 4) as $ignored) {
            Artisan::call('queue:work', ['--once' => true, 'connection' => 'database']);
            $ids[] = latestCapture()->id;
        }

        $trace = app(CaptureReader::class)->trace($ids[0]);

        expect(array_column($trace, 'id'))->toBe($ids)
            ->and(array_column($trace, 'depth'))->toBe([0, 1, 2, 3, 4])
            ->and(array_column($trace, 'parent_id'))->toBe([null, ...array_slice($ids, 0, 4)]);
    });

    it('marks failed jobs with the exception', function (): void {
        $this->get('/dispatch-failing')->assertOk();

        Artisan::call('queue:work', ['--once' => true, 'connection' => 'database']);

        $job = latestCapture();

        expect($job->type)->toBe(CaptureType::Job)
            ->and($job->status)->toBe('failed')
            ->and($job->section('job')['exception']['class'])->toBe(RuntimeException::class)
            ->and($job->section('job')['exception']['message'])->toBe('job exploded')
            ->and($job->health['open_spans_at_finalize'])->toBe(0);

        $summary = app(QueueLens::class)->summarize($job);

        expect(implode(' ', $summary->errors))->toContain('job exploded');
    });

    it('records jobs on the sync queue as spans inside the dispatching capture', function () use ($captures): void {
        $this->get('/inline')->assertOk();

        $request = latestCapture();
        $spans = $request->spansOfKind('job');

        expect(RecordingJob::$handled)->toBe(1)
            ->and($request->rows('jobs')[0]['sync'])->toBeTrue()
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->name)->toBe(RecordingJob::class)
            ->and($spans[0]->meta['status'])->toBe('processed')
            ->and($captures('job'))->toBe([]);
    });

    it('records dispatchSync of a queueable job once, via the sync queue', function (): void {
        $this->get('/now')->assertOk();

        $spans = latestCapture()->spansOfKind('job');

        expect(RecordingJob::$handled)->toBe(1)
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->meta['sync'])->toBeTrue()
            ->and($spans[0]->meta['status'])->toBe('processed');
    });

    it('records plain bus commands through the dispatch pipe', function (): void {
        PlainCommand::$handled = 0;

        $this->get('/plain')->assertOk();

        $spans = latestCapture()->spansOfKind('job');

        expect(PlainCommand::$handled)->toBe(1)
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->name)->toBe(PlainCommand::class)
            ->and($spans[0]->meta['via'])->toBe('dispatchSync')
            ->and($spans[0]->meta['status'])->toBe('processed')
            ->and($spans[0]->duration())->toBeGreaterThanOrEqual(0);
    });

    it('links no payload to a capture outside a capture or while it is discarded', function (): void {
        Queue::connection('database')->push(new RecordingJob('outside'));

        app(Eyepiece::class)->start(CaptureType::Command);
        app(Eyepiece::class)->discard();
        Queue::connection('database')->push(new RecordingJob('discarded'));

        $payloads = DB::table('jobs')->pluck('payload')->map(fn (string $payload): array => json_decode($payload, true));

        expect($payloads)->toHaveCount(2)
            ->and($payloads->every(fn (array $payload): bool => ! array_key_exists(QueueLens::PAYLOAD_KEY, $payload)))->toBeTrue()
            ->and(app(Eyepiece::class)->finalize())->toBeNull();
    });

    it('records nothing for an inline job while the capture is discarded', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);
        app(Eyepiece::class)->discard();

        RecordingJob::dispatch('discarded')->onConnection('sync');

        expect(RecordingJob::$handled)->toBe(1)
            ->and(app(Eyepiece::class)->active()?->spansOfKind('job'))->toBe([])
            ->and(app(Eyepiece::class)->active()?->rows('jobs'))->toBe([]);
    });

    it('marks an inline job that throws as failed on its span', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);

        expect(fn () => FailingJob::dispatch()->onConnection('sync'))->toThrow(RuntimeException::class, 'job exploded');

        $capture = app(Eyepiece::class)->finalize();
        $spans = $capture->spansOfKind('job');

        expect($spans)->toHaveCount(1)
            ->and($spans[0]->isOpen())->toBeFalse()
            ->and($spans[0]->meta['status'])->toBe('exception')
            ->and($spans[0]->meta['exception']['message'])->toBe('job exploded')
            ->and($capture->status)->toBeNull();
    });

    it('records raw queued payloads with their class name and a date delay', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);
        $delay = CarbonImmutable::parse('2026-01-02 03:04:05', 'UTC');

        event(new JobQueued(null, 'reports', 7, 'App\\Jobs\\BuildReport', json_encode(['data' => []]), $delay));

        $row = app(Eyepiece::class)->finalize()->rows('jobs')[0];

        expect($row)->toMatchArray([
            'job' => 'App\\Jobs\\BuildReport',
            'connection' => null,
            'queue' => 'reports',
            'delay' => '2026-01-02T03:04:05+00:00',
            'job_id' => 7,
            'batch_id' => null,
            'chained' => 0,
            'sync' => false,
            'run' => 7,
        ]);
    });

    it('keeps working with a custom bus dispatcher without pipes', function (): void {
        $dispatcher = Mockery::mock(BusDispatcher::class);
        $dispatcher->shouldNotReceive('pipeThrough');
        app()->instance(BusDispatcher::class, $dispatcher);

        new QueueLens(app(Eyepiece::class))->boot(app());

        expect(app(BusDispatcher::class))->toBe($dispatcher);
    });

    it('shows the facts of a job capture on a Job tab', function (): void {
        $this->get('/dispatch')->assertOk();

        Artisan::call('queue:work', ['--once' => true, 'connection' => 'database']);

        $tabs = app(\ExeQue\Monocle\Dashboard\TabList::class)->for(latestCapture(), [], []);
        $job = array_values(array_filter($tabs, fn (array $tab): bool => $tab['key'] === 'job'))[0];

        expect($job['label'])->toBe('Job')
            ->and(app(QueueLens::class)->presentation()->included['job']->tabView)->toBe('monocle::tabs.job');
    });

    it('presents dispatched jobs with a link to their own capture', function (): void {
        $presentation = app(QueueLens::class)->presentation();

        expect($presentation->tabView)->toBe('monocle::tabs.jobs')
            ->and($presentation->groupings)->toBe(['job' => 'by job', 'queue' => 'by queue'])
            ->and($presentation->formats)->toBe(['run' => ['capture', 'job_id']]);
    });
});
