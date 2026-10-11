<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\ScheduleLens;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use RuntimeException;

covers(ScheduleLens::class);

describe(ScheduleLens::class, function (): void {
    it('records task outcomes on the running capture and summarizes them', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);

        $schedule = app(Schedule::class);
        $ok = $schedule->command('inspire')->everyMinute()->description('Daily wisdom');
        $failed = $schedule->command('backup:run')->daily();
        $skipped = $schedule->command('cleanup')->hourly();

        event(new ScheduledTaskStarting($ok));
        event(new ScheduledTaskFinished($ok, 0.25));
        event(new ScheduledTaskFailed($failed, new RuntimeException('disk full')));
        event(new ScheduledTaskSkipped($skipped));

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('scheduled_tasks');

        expect(array_column($rows, 'outcome'))->toBe(['starting', 'finished', 'failed', 'skipped'])
            ->and($rows[1]['runtime_ms'])->toBe(250.0)
            ->and($rows[1]['expression'])->toBe('* * * * *')
            ->and($rows[1]['description'])->toBe('Daily wisdom')
            ->and($rows[1]['command'])->toContain('inspire')
            ->and($rows[2]['exception']['message'])->toBe('disk full');

        $summary = app(ScheduleLens::class)->summarize($capture);

        expect($summary->totals)->toBe(['scheduled_tasks_run' => 1, 'scheduled_tasks_skipped' => 1, 'scheduled_tasks_failed' => 1])
            ->and($summary->errors[0])->toContain('backup:run');
    });

    it('records closure tasks finished in the background under their display summary', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);

        $task = app(Schedule::class)->call(fn (): null => null)->description('Prune drafts');

        event(new ScheduledBackgroundTaskFinished($task));

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('scheduled_tasks');

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray(['command' => 'Prune drafts', 'outcome' => 'finished_in_background'])
            ->and(app(ScheduleLens::class)->summarize($capture)->totals['scheduled_tasks_run'])->toBe(1);
    });

    it('ignores tasks outside a capture and summarizes nothing for none', function (): void {
        event(new ScheduledTaskStarting(app(Schedule::class)->command('inspire')));

        app(Eyepiece::class)->start(CaptureType::Command);

        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('scheduled_tasks'))->toBe([])
            ->and(app(ScheduleLens::class)->summarize($capture))->toEqual(Summary::make());
    });

    it('presents scheduled tasks as a table', function (): void {
        expect(app(ScheduleLens::class)->presentation()->tabView)->toBe('monocle::tabs.scheduled_tasks');
    });
});
