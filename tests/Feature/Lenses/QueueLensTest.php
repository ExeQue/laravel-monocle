<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\EntryType;
use ExeQue\Monocle\Lenses\Queue\SyncDispatchPipe;
use ExeQue\Monocle\Lenses\QueueLens;
use ExeQue\Monocle\Reader\EntryReader;
use ExeQue\Monocle\Reader\Filter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Jobs\FailingJob;
use Tests\Fixtures\Jobs\PlainCommand;
use Tests\Fixtures\Jobs\RecordingJob;

covers(QueueLens::class, SyncDispatchPipe::class);

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

    $entries = fn (?string $type = null): array => app(EntryReader::class)->list(Filter::fromArray(['type' => $type, 'limit' => 50]))['rows'];

    it('records dispatched jobs and links the worker-side entry to the request', function () use ($entries): void {
        $this->get('/dispatch')->assertOk();

        $request = latestEntry();
        $dispatched = $request->rows('jobs');

        expect($dispatched)->toHaveCount(1)
            ->and($dispatched[0]['job'])->toBe(RecordingJob::class)
            ->and($dispatched[0]['connection'])->toBe('database')
            ->and($dispatched[0]['sync'])->toBeFalse()
            ->and($dispatched[0]['job_id'])->toBeString();

        Artisan::call('queue:work', ['--once' => true, 'connection' => 'database']);

        $job = latestEntry();

        expect(RecordingJob::$handled)->toBe(1)
            ->and($job->type)->toBe(EntryType::Job)
            ->and($job->name)->toBe(RecordingJob::class)
            ->and($job->status)->toBe('processed')
            ->and($job->parentId)->toBe($request->id)
            ->and($job->traceId)->toBe($request->id)
            ->and($job->section('job')['job_id'])->toBe($dispatched[0]['job_id'])
            ->and($job->section('job')['attempts'])->toBe(1)
            ->and($job->rows('cache'))->not->toBeEmpty()
            ->and($entries('job'))->toHaveCount(1)
            ->and($entries('request'))->toHaveCount(1);

        $trace = app(EntryReader::class)->trace($request->id);

        expect(array_column($trace, 'depth'))->toBe([0, 1])
            ->and($trace[1]['offset_ms'])->toBeGreaterThan(0);
    });

    it('marks failed jobs with the exception', function (): void {
        $this->get('/dispatch-failing')->assertOk();

        Artisan::call('queue:work', ['--once' => true, 'connection' => 'database']);

        $job = latestEntry();

        expect($job->type)->toBe(EntryType::Job)
            ->and($job->status)->toBe('failed')
            ->and($job->section('job')['exception']['class'])->toBe(RuntimeException::class)
            ->and($job->section('job')['exception']['message'])->toBe('job exploded');

        $summary = (new QueueLens())->summarize($job);

        expect(implode(' ', $summary['warnings']))->toContain('job exploded');
    });

    it('records jobs on the sync queue as spans inside the dispatching entry', function () use ($entries): void {
        $this->get('/inline')->assertOk();

        $request = latestEntry();
        $spans = $request->spansOfKind('job');

        expect(RecordingJob::$handled)->toBe(1)
            ->and($request->rows('jobs')[0]['sync'])->toBeTrue()
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->name)->toBe(RecordingJob::class)
            ->and($spans[0]->meta['status'])->toBe('processed')
            ->and($entries('job'))->toBe([]);
    });

    it('records dispatchSync of a queueable job once, via the sync queue', function (): void {
        $this->get('/now')->assertOk();

        $spans = latestEntry()->spansOfKind('job');

        expect(RecordingJob::$handled)->toBe(1)
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->meta['sync'])->toBeTrue()
            ->and($spans[0]->meta['status'])->toBe('processed');
    });

    it('records plain bus commands through the dispatch pipe', function (): void {
        PlainCommand::$handled = 0;

        $this->get('/plain')->assertOk();

        $spans = latestEntry()->spansOfKind('job');

        expect(PlainCommand::$handled)->toBe(1)
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->name)->toBe(PlainCommand::class)
            ->and($spans[0]->meta['via'])->toBe('dispatchSync')
            ->and($spans[0]->meta['status'])->toBe('processed')
            ->and($spans[0]->duration())->toBeGreaterThanOrEqual(0);
    });
});
