<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Process\MonocleProcessFactory;
use ExeQue\Monocle\Lenses\Process\ProcessRecorder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;

/**
 * External processes started through the Process facade. Laravel fires no
 * events for processes, so the process factory is replaced with one whose
 * pending processes record what they ran.
 */
final class ProcessLens implements Advises, Configurable, Finalizable, Lens, Presentable, Resettable, Summarizable
{
    private const int MAX_MESSAGES = 3;

    private float $slowMs = 1000.0;

    private readonly ProcessRecorder $recorder;

    public function __construct(Eyepiece $eyepiece, Application $app)
    {
        $this->recorder = new ProcessRecorder($eyepiece, $app->basePath());
    }

    public function key(): string
    {
        return 'processes';
    }

    public function description(): string
    {
        return 'External processes started with the Process facade (run, start, pool, pipe): command line, working directory, exit code, successful, timed_out, duration, output and error output sizes, async for background processes and the exception on failure. Each process is a process span in the timeline. Processes started without the facade are not covered.';
    }

    public function advice(): string
    {
        return 'Look here when a shell command a request or job runs is slow, fails or times out.';
    }

    public function configure(array $options): void
    {
        $this->slowMs = (float) ($options['slow_ms'] ?? $this->slowMs);
    }

    /**
     * Process::fake() hands the facade's factory back to the container with
     * instance(), which skips extenders, so a factory swapped in that way is
     * wrapped on rebinding too.
     */
    public function boot(Application $app): void
    {
        $wrap = fn (Factory $factory): Factory => $factory instanceof MonocleProcessFactory
            ? $factory
            : MonocleProcessFactory::wrap($factory, $this->recorder);

        $app->extend(Factory::class, $wrap);

        $app->rebinding(Factory::class, function (Application $app, Factory $factory) use ($wrap): void {
            if ($factory instanceof MonocleProcessFactory) {
                return;
            }

            $app->instance(Factory::class, $wrap($factory));
        });

        Process::clearResolvedInstance(Factory::class);
    }

    public function finalize(Capture $capture): void
    {
        $this->recorder->finalize();
    }

    public function reset(): void
    {
        $this->recorder->reset();
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('processes');

        if ($rows === []) {
            return Summary::make();
        }

        $ms = 0.0;
        $failed = [];
        $slow = [];

        foreach ($rows as $row) {
            $duration = (float) ($row['duration'] ?? 0);
            $ms += $duration;

            if ($duration >= $this->slowMs) {
                $slow[] = sprintf('Process %s took %s ms.', $row['command'], round($duration, 1));
            }

            if ($row['timed_out'] ?? false) {
                $failed[] = sprintf('Process %s timed out.', $row['command']);

                continue;
            }

            if (($row['successful'] ?? null) === false) {
                $failed[] = sprintf('Process %s failed (%s).', $row['command'], $this->failureReason($row));
            }
        }

        return Summary::make()
            ->total('processes_ms', round($ms, 3), 'spent', Unit::Milliseconds)
            ->total('processes', count($rows), 'processes')
            ->total('processes_failed', count($failed), 'failed')
            ->errors(array_slice($failed, 0, self::MAX_MESSAGES))
            ->warnings(array_slice($slow, 0, self::MAX_MESSAGES));
    }

    /**
     * The exception message, or the exit code when the process failed without one.
     *
     * @param  array<string, mixed>  $row
     */
    private function failureReason(array $row): string
    {
        if (isset($row['exception']['message'])) {
            return (string) $row['exception']['message'];
        }

        return 'exit code '.($row['exit_code'] ?? '?');
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.processes')
            ->columns([
                'command',
                'path',
                'exit_code' => 'Exit',
                'successful' => 'OK',
                'timed_out',
                'async',
                'duration',
                'output_bytes' => 'Output',
                'error_output_bytes' => 'Errors',
            ])
            ->format([
                'command' => 'code',
                'exit_code' => 'status',
                'successful' => ['flag', 'ok', 'failed'],
                'timed_out' => ['flag', 'timed out', null, 'bad', 'bad'],
            ])
            ->groupBy(['command' => 'by command']);
    }
}
