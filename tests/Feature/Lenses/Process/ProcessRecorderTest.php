<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Process;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Process\ProcessRecorder;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

covers(ProcessRecorder::class);

describe(ProcessRecorder::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('shortens working directories below the base path and keeps the others', function (): void {
        $recorder = new ProcessRecorder(app(Eyepiece::class), '/base');

        foreach (['/base', '/base/sub/dir', '/elsewhere', null] as $path) {
            $recorder->finish($recorder->begin('true', $path, async: false));
        }

        expect(array_column(app(Eyepiece::class)->finalize()->rows('processes'), 'path'))->toBe(['.', 'sub/dir', '/elsewhere', null]);
    });

    it('leaves the output sizes unknown when the result cannot report them', function (): void {
        $recorder = new ProcessRecorder(app(Eyepiece::class), base_path());

        // A process that never started throws when asked for its output.
        $recorder->finish($recorder->begin('true', null, async: false), new ProcessResult(new SymfonyProcess(['true'])));

        $row = app(Eyepiece::class)->finalize()->rows('processes')[0];

        expect($row['output_bytes'])->toBeNull()
            ->and($row['error_output_bytes'])->toBeNull()
            ->and($row['exit_code'])->toBeNull()
            ->and($row['successful'])->toBeFalse();
    });

    it('drops a process that finishes after the capture ended', function (): void {
        $recorder = new ProcessRecorder(app(Eyepiece::class), base_path());
        $ticket = $recorder->begin('true', null, async: true);

        $capture = app(Eyepiece::class)->finalize();
        $recorder->finish($ticket, Process::result());

        app(Eyepiece::class)->start(CaptureType::Request);
        $recorder->finalize();

        expect($capture->rows('processes'))->toBe([])
            ->and(app(Eyepiece::class)->finalize()->rows('processes'))->toBe([]);
    });
});
