<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\ThrowableChain;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;

/**
 * Scheduled tasks run by schedule:run, recorded on that command's capture.
 * Tasks run as subprocesses, so their own output lands in separate captures.
 */
final class ScheduleLens implements Advises, Lens, Presentable, Summarizable
{
    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'scheduled_tasks';
    }

    public function description(): string
    {
        return 'Scheduled tasks evaluated by schedule:run: command, cron expression, description, outcome (finished, failed, skipped), runtime_ms and the exception for failures.';
    }

    public function advice(): string
    {
        return 'Look here when a scheduled task did not run, ran late or failed.';
    }

    public function boot(): void
    {
        $this->eyepiece->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            $this->record($event->task, 'starting');
        });

        $this->eyepiece->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            $runtimeMs = round($event->runtime * 1000, 3);

            $this->record($event->task, 'finished', ['runtime_ms' => $runtimeMs]);
        });

        $this->eyepiece->listen(ScheduledBackgroundTaskFinished::class, function (ScheduledBackgroundTaskFinished $event): void {
            $this->record($event->task, 'finished_in_background');
        });

        $this->eyepiece->listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event): void {
            $this->record($event->task, 'skipped');
        });

        $this->eyepiece->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            $exception = ThrowableChain::describe($event->exception);

            $this->record($event->task, 'failed', ['exception' => $exception]);
        });
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('scheduled_tasks');

        if ($rows === []) {
            return Summary::make();
        }

        $run = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $row) {
            $outcome = $row['outcome'];

            // Both 'finished' and 'finished_in_background' count as run.
            if (str_starts_with($outcome, 'finished')) {
                $run++;
            }

            if ($outcome === 'skipped') {
                $skipped++;
            }

            if ($outcome === 'failed') {
                $errors[] = "Scheduled task failed: {$row['command']}";
            }
        }

        return Summary::make()
            ->total('scheduled_tasks_run', $run, 'run')
            ->total('scheduled_tasks_skipped', $skipped, 'skipped')
            ->total('scheduled_tasks_failed', count($errors), 'failed')
            ->errors($errors);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(Event $task, string $outcome, array $extra = []): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->eyepiece->push('scheduled_tasks', [
            'command' => $this->commandName($task),
            'expression' => $task->getExpression(),
            'description' => $task->description,
            'outcome' => $outcome,
            'runtime_ms' => $extra['runtime_ms'] ?? null,
            'exception' => $extra['exception'] ?? null,
        ], origin: false);
    }

    /**
     * The command line, or for a closure or job task its display summary.
     */
    private function commandName(Event $task): string
    {
        if (is_string($task->command) && $task->command !== '') {
            return $task->command;
        }

        return $task->getSummaryForDisplay();
    }

    public function presentation(): Presentation
    {
        return Presentation::table()->tab('monocle::tabs.scheduled_tasks');
    }
}
