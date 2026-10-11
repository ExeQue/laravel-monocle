<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Lenses\Queue\SyncDispatchPipe;
use ExeQue\Monocle\Lenses\QueueLens;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Fixtures\Jobs\DispatchingJob;
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
        Route::get('/dispatch-nested', function () {
            DispatchingJob::dispatch()->onConnection('database');

            return 'dispatched';
        });

        $this->get('/dispatch-nested')->assertOk();
        $request = latestCapture();

        Artisan::call('queue:work', ['--once' => true, 'connection' => 'database']);
        $outer = latestCapture();

        Artisan::call('queue:work', ['--once' => true, 'connection' => 'database']);
        $inner = latestCapture();

        $trace = app(CaptureReader::class)->trace($request->id);

        expect($outer->parentId)->toBe($request->id)
            ->and($inner->parentId)->toBe($outer->id)
            ->and([$outer->traceId, $inner->traceId])->toBe([$request->id, $request->id])
            ->and(array_column($trace, 'id'))->toBe([$request->id, $outer->id, $inner->id])
            ->and(array_column($trace, 'depth'))->toBe([0, 1, 2])
            ->and(array_column(app(CaptureReader::class)->trace($request->id), 'id'))->toBe(array_column(app(CaptureReader::class)->trace($inner->traceId), 'id'));
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
});
