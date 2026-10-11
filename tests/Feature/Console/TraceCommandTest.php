<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\TraceCommand;
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
            ->and(array_column($data['captures'], 'id'))->toBe([$this->root, $this->countdown, $this->failing]);
    });

    it('fails for unknown captures', function (): void {
        $this->artisan('monocle:trace', ['id' => 'missing'])->assertFailed();
    });
});
