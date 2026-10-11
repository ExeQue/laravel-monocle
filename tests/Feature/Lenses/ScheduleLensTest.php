<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\ScheduleLens;
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

        $summary = (new ScheduleLens())->summarize($capture);

        expect($summary['totals'])->toBe(['scheduled_tasks_run' => 1, 'scheduled_tasks_skipped' => 1, 'scheduled_tasks_failed' => 1])
            ->and($summary['warnings'][0])->toContain('backup:run');
    });
});
