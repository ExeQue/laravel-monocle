<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Process;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Process\RecordingInvokedProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessSignaledException;

covers(RecordingInvokedProcess::class);

describe(RecordingInvokedProcess::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('passes the process api through to the wrapped background process', function (): void {
        $process = Process::start(['php', '-r', 'echo "out"; fwrite(STDERR, "err"); sleep(5);']);
        $seen = [];

        $process->waitUntil(function (string $type) use (&$seen): bool {
            $seen[$type] = true;

            return count($seen) === 2;
        });

        $sigterm = 15;

        expect($process)->toBeInstanceOf(RecordingInvokedProcess::class)
            ->and($process->id())->toBeInt()->toBeGreaterThan(0)
            ->and($process->command())->toBe("'php' '-r' 'echo \"out\"; fwrite(STDERR, \"err\"); sleep(5);'")
            ->and($process->output())->toBe('out')
            ->and($process->errorOutput())->toBe('err')
            ->and($process->latestOutput())->toBe('out')
            ->and($process->latestErrorOutput())->toBe('err')
            ->and($process->ensureNotTimedOut())->toBeNull()
            ->and(app(Eyepiece::class)->active()->rows('processes'))->toBe([])
            ->and($process->signal($sigterm))->toBe($process);

        $process->wait();

        expect(app(Eyepiece::class)->finalize()->rows('processes'))->toHaveCount(1)->{0}->toMatchArray(['async' => true, 'successful' => false]);
    });

    it('records the exception when waiting on a background process fails', function (): void {
        // The shell kills itself, so Symfony reports a signal it did not send.
        $process = Process::start('kill -9 $$');

        expect(fn () => $process->wait())->toThrow(ProcessSignaledException::class);

        $row = app(Eyepiece::class)->finalize()->rows('processes');

        expect($row)->toHaveCount(1)
            ->and($row[0])->toMatchArray(['command' => 'kill -9 $$', 'async' => true, 'successful' => false, 'exit_code' => null])
            ->and($row[0]['exception']['class'] ?? null)->toBe(ProcessSignaledException::class);
    });

    it('records the exception when collecting the result after running() fails', function (): void {
        $process = Process::start('kill -9 $$');

        while ($process->running()) {
            usleep(1000);
        }

        $row = app(Eyepiece::class)->finalize()->rows('processes');

        expect($process->running())->toBeFalse()
            ->and($row)->toHaveCount(1)
            ->and($row[0])->toMatchArray(['async' => true, 'successful' => false])
            ->and($row[0]['exception']['class'] ?? null)->toBe(ProcessSignaledException::class);
    });
});
