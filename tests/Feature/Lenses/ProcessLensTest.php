<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Process\MonocleProcessFactory;
use ExeQue\Monocle\Lenses\Process\ProcessRecorder;
use ExeQue\Monocle\Lenses\Process\RecordingInvokedProcess;
use ExeQue\Monocle\Lenses\Process\RecordingPendingProcess;
use ExeQue\Monocle\Lenses\ProcessLens;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

covers(ProcessLens::class, MonocleProcessFactory::class, ProcessRecorder::class, RecordingInvokedProcess::class, RecordingPendingProcess::class);

describe(ProcessLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('replaces the process factory and records a run with its span', function (): void {
        $result = Process::path(base_path())->run(['php', '-r', 'echo "hello";']);

        $capture = app(Eyepiece::class)->finalize();
        $row = $capture->rows('processes')[0];
        $spans = array_values(array_filter($capture->spans, fn (Span $span): bool => $span->kind === 'process'));

        expect(app(Factory::class))->toBeInstanceOf(MonocleProcessFactory::class)
            ->and($result->output())->toBe('hello')
            ->and($row)->toMatchArray([
                'command' => "'php' '-r' 'echo \"hello\";'",
                'path' => '.',
                'exit_code' => 0,
                'successful' => true,
                'timed_out' => false,
                'output_bytes' => 5,
                'error_output_bytes' => 0,
                'async' => false,
                'exception' => null,
            ])
            ->and($row['duration'])->toBeGreaterThan(0)
            ->and($row['time'])->toBeGreaterThanOrEqual(0)
            ->and($row['origin']['file'] ?? '')->toContain('ProcessLensTest.php')
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->name)->toBe($row['command'])
            ->and($spans[0]->start)->toBe($row['time'])
            ->and($spans[0]->meta)->toMatchArray(['exit_code' => 0, 'async' => false]);
    });

    it('turns a failing exit code into an error', function (): void {
        Process::run(['php', '-r', 'exit(3);']);

        $capture = app(Eyepiece::class)->finalize();
        $summary = app(ProcessLens::class)->summarize($capture);

        expect($capture->rows('processes')[0])->toMatchArray(['exit_code' => 3, 'successful' => false])
            ->and($summary->totals)->toMatchArray(['processes' => 1, 'processes_failed' => 1])
            ->and(array_key_first($summary->totals))->toBe('processes_ms')
            ->and($summary->errors)->toBe(["Process 'php' '-r' 'exit(3);' failed (exit code 3)."])
            ->and($summary->warnings)->toBe([]);
    });

    it('records a timeout with its exception', function (): void {
        expect(fn () => Process::timeout(1)->run(['php', '-r', 'sleep(3);']))->toThrow(ProcessTimedOutException::class);

        $capture = app(Eyepiece::class)->finalize();
        $row = $capture->rows('processes')[0];

        expect($row)->toMatchArray(['timed_out' => true, 'successful' => false])
            ->and($row['exception']['class'] ?? null)->toBe(ProcessTimedOutException::class)
            ->and(app(ProcessLens::class)->summarize($capture)->errors)->toBe(["Process 'php' '-r' 'sleep(3);' timed out."]);
    });

    it('records a background process from start until its result is known', function (): void {
        $process = Process::start(['php', '-r', 'usleep(50000); echo "done";']);

        expect($process)->toBeInstanceOf(RecordingInvokedProcess::class)
            ->and(app(Eyepiece::class)->active()->rows('processes'))->toBe([]);

        $result = $process->wait();
        $process->wait();

        $row = app(Eyepiece::class)->finalize()->rows('processes');

        expect($result->output())->toBe('done')
            ->and($row)->toHaveCount(1)
            ->and($row[0])->toMatchArray(['async' => true, 'exit_code' => 0, 'successful' => true, 'output_bytes' => 4])
            ->and($row[0]['duration'])->toBeGreaterThanOrEqual(50.0);
    });

    it('records a background process once running() reports it finished', function (): void {
        $process = Process::start(['php', '-r', 'exit(2);']);

        while ($process->running()) {
            usleep(1000);
        }

        expect($process->running())->toBeFalse()
            ->and(app(Eyepiece::class)->finalize()->rows('processes'))->toHaveCount(1)->{0}->toMatchArray(['async' => true, 'exit_code' => 2]);
    });

    it('records every process of a pool', function (): void {
        $results = Process::pool(function (Pool $pool): void {
            $pool->as('one')->command(['php', '-r', 'echo 1;']);
            $pool->as('two')->command(['php', '-r', 'exit(1);']);
        })->start()->wait();

        $rows = app(Eyepiece::class)->finalize()->rows('processes');

        expect($results['one']->output())->toBe('1')
            ->and($rows)->toHaveCount(2)
            ->and(array_column($rows, 'async'))->toBe([true, true])
            ->and(array_column($rows, 'exit_code'))->toEqualCanonicalizing([0, 1]);
    });

    it('keeps recording when the process facade is faked', function (): void {
        Process::fake(['*' => Process::result('faked', exitCode: 4)]);

        $result = Process::run('ls -la');

        $row = app(Eyepiece::class)->finalize()->rows('processes')[0];

        expect($result->output())->toBe("faked\n")
            ->and($row)->toMatchArray(['command' => 'ls -la', 'exit_code' => 4, 'successful' => false]);

        Process::assertRan('ls -la');
    });

    it('warns about processes slower than slow_ms', function (): void {
        Process::run(['php', '-r', 'echo 1;']);

        $lens = app(ProcessLens::class);
        $lens->configure(['slow_ms' => 0]);
        $summary = $lens->summarize(app(Eyepiece::class)->finalize());

        expect($summary->warnings)->toHaveCount(1)
            ->and($summary->warnings[0])->toMatch("/^Process 'php' '-r' 'echo 1;' took [\\d.]+ ms\\.$/")
            ->and($summary->errors)->toBe([]);
    });

    it('closes a background process nobody waited for when the capture ends', function (): void {
        $process = Process::start(['php', '-r', 'usleep(200000);']);

        $capture = app(Eyepiece::class)->finalize();
        $process->wait();
        $spans = array_values(array_filter($capture->spans, fn (Span $span): bool => $span->kind === 'process'));

        expect($capture->rows('processes'))->toHaveCount(1)
            ->and($capture->rows('processes')[0])->toMatchArray(['async' => true, 'exit_code' => null, 'successful' => null, 'output_bytes' => null])
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->isOpen())->toBeFalse()
            ->and(app(ProcessLens::class)->summarize($capture)->errors)->toBe([]);
    });

    it('records nothing outside a capture', function (): void {
        app(Eyepiece::class)->finalize();

        $process = Process::start(['php', '-r', 'echo 1;']);
        Process::run(['php', '-r', 'echo 1;']);

        expect($process)->not->toBeInstanceOf(RecordingInvokedProcess::class)
            ->and($process->wait()->output())->toBe('1');

        app(Eyepiece::class)->start(CaptureType::Request);

        expect(app(Eyepiece::class)->finalize()->rows('processes'))->toBe([]);
    });

    it('summarizes nothing without processes', function (): void {
        expect(app(ProcessLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(\ExeQue\Monocle\Data\Summary::make());
    });
});
