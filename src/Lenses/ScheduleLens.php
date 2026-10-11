<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Foundation\Application;

/**
 * Scheduled tasks run by schedule:run, recorded on that command's capture.
 * Tasks run as subprocesses, so their own output lands in separate captures.
 */
final class ScheduleLens implements Advises, Lens, Summarizable
{
    private Eyepiece $eyepiece;

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

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        $eyepiece->listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $this->record($event->task, 'starting'));
        $eyepiece->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $this->record($event->task, 'finished', ['runtime_ms' => round($event->runtime * 1000, 3)]));
        $eyepiece->listen(ScheduledBackgroundTaskFinished::class, fn (ScheduledBackgroundTaskFinished $event) => $this->record($event->task, 'finished_in_background'));
        $eyepiece->listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => $this->record($event->task, 'skipped'));
        $eyepiece->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $this->record($event->task, 'failed', [
            'exception' => ['class' => $event->exception::class, 'message' => $event->exception->getMessage()],
        ]));
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('scheduled_tasks');

        if ($rows === []) {
            return [];
        }

        $failed = array_filter($rows, fn (array $row): bool => $row['outcome'] === 'failed');

        return [
            'totals' => [
                'scheduled_tasks_run' => count(array_filter($rows, fn (array $row): bool => str_starts_with($row['outcome'], 'finished'))),
                'scheduled_tasks_skipped' => count(array_filter($rows, fn (array $row): bool => $row['outcome'] === 'skipped')),
                'scheduled_tasks_failed' => count($failed),
            ],
            'warnings' => array_map(fn (array $row): string => "Scheduled task failed: {$row['command']}", array_values($failed)),
        ];
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
            'command' => is_string($task->command) && $task->command !== '' ? $task->command : $task->getSummaryForDisplay(),
            'expression' => $task->getExpression(),
            'description' => $task->description,
            'outcome' => $outcome,
            'runtime_ms' => $extra['runtime_ms'] ?? null,
            'exception' => $extra['exception'] ?? null,
        ], origin: false);
    }
}
