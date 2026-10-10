<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\EntryType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\ScheduleLens;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;

covers(ScheduleLens::class);

describe(ScheduleLens::class, function (): void {
    it('records task outcomes on the running entry and summarizes them', function (): void {
        app(Eyepiece::class)->start(EntryType::Command);

        $schedule = app(Schedule::class);
        $ok = $schedule->command('inspire')->everyMinute()->description('Daily wisdom');
        $failed = $schedule->command('backup:run')->daily();
        $skipped = $schedule->command('cleanup')->hourly();

        event(new ScheduledTaskStarting($ok));
        event(new ScheduledTaskFinished($ok, 0.25));
        event(new ScheduledTaskFailed($failed, new RuntimeException('disk full')));
        event(new ScheduledTaskSkipped($skipped));

        $entry = app(Eyepiece::class)->finalize();
        $rows = $entry->rows('scheduled_tasks');

        expect(array_column($rows, 'outcome'))->toBe(['starting', 'finished', 'failed', 'skipped'])
            ->and($rows[1]['runtime_ms'])->toBe(250.0)
            ->and($rows[1]['expression'])->toBe('* * * * *')
            ->and($rows[1]['description'])->toBe('Daily wisdom')
            ->and($rows[1]['command'])->toContain('inspire')
            ->and($rows[2]['exception']['message'])->toBe('disk full');

        $summary = (new ScheduleLens())->summarize($entry);

        expect($summary['totals'])->toBe(['scheduled_tasks_run' => 1, 'scheduled_tasks_skipped' => 1, 'scheduled_tasks_failed' => 1])
            ->and($summary['warnings'][0])->toContain('backup:run');
    });
});
