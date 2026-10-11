<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\TraceCommand;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Jobs\CountdownJob;
use Tests\Fixtures\Jobs\FailingJob;

covers(TraceCommand::class);

describe(TraceCommand::class, function (): void {
    beforeEach(function (): void {
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

        Route::get('/fan-out', function () {
            CountdownJob::dispatch(1)->onConnection('database');
            FailingJob::dispatch()->onConnection('database');

            return 'dispatched';
        });

        $this->get('/fan-out')->assertOk();
        $this->root = latestCapture()->id;

        Artisan::call('queue:work', ['--once' => true, 'connection' => 'database']);
        $this->countdown = latestCapture()->id;

        Artisan::call('queue:work', ['--once' => true, 'connection' => 'database']);
        $this->failing = latestCapture()->id;
    });

    it('draws the trace as a tree with failed captures and pending jobs marked', function (): void {
        Artisan::call('monocle:trace', ['id' => $this->countdown]);
        $lines = explode("\n", trim(Artisan::output()));

        expect($lines[0])->toStartWith('request GET /fan-out  200  ')->toContain($this->root)->not->toContain('requested')
            ->and($lines[1])->toStartWith('├─ job CountdownJob  processed  ')->toContain('at +')->toContain($this->countdown)->toEndWith('<- requested')
            ->and($lines[2])->toStartWith('│  └─ job CountdownJob  PENDING, no capture yet  job id ')
            ->and($lines[3])->toStartWith('└─ job FailingJob  failed FAILED  ')->toContain('errors')->toContain($this->failing)
            ->and(end($lines))->toContain('3 captures,')->toContain('1 failed, 1 pending.');
    });

    it('still prints the JSON payload with --json', function (): void {
        Artisan::call('monocle:trace', ['id' => $this->root, '--json' => true]);
        $data = json_decode(Artisan::output(), true);

        expect($data['id'])->toBe($this->root)
            ->and(array_column($data['captures'], 'id'))->toBe([$this->root, $this->countdown, $this->failing])
            ->and($data['pending'])->toHaveCount(1)
            ->and($data['pending'][0]['parent_id'])->toBe($this->countdown);
    });

    it('fails for unknown captures', function (): void {
        $this->artisan('monocle:trace', ['id' => 'missing'])->assertFailed();
    });

    it('draws a capture whose parent is gone as a root and counts its warnings', function (): void {
        $orphan = new Capture('orphan', CaptureType::Job, microtime(true), parentId: 'pruned', traceId: 'orphan-trace');
        $orphan->name = CountdownJob::class;
        $orphan->status = 'processed';
        $orphan->endedAt = $orphan->startedAt + 0.01;
        $orphan->health = ['warnings' => 2];
        app(StoreManager::class)->store()->put($orphan);

        Artisan::call('monocle:trace', ['id' => 'orphan']);
        $lines = explode("\n", trim(Artisan::output()));

        expect($lines[0])->toStartWith('job CountdownJob  processed  ')->toContain('2 warnings')->toEndWith('<- requested')
            ->and(end($lines))->toContain('1 capture,')->toContain('0 failed.');
    });
});
