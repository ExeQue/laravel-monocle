<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\LogLens;
use Illuminate\Support\Facades\Log;
use RuntimeException;

covers(LogLens::class);

describe(LogLens::class, function (): void {
    it('records log messages with reduced exceptions in context and summarizes levels', function (): void {
        config(['logging.default' => 'null']);
        app(Eyepiece::class)->start(CaptureType::Request);

        Log::info('Started import', ['album' => 3]);
        Log::error('Import failed', ['exception' => new RuntimeException('disk full')]);
        Log::debug('noise');

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('log');

        expect(array_column($rows, 'level'))->toBe(['info', 'error', 'debug'])
            ->and($rows[0]['context'])->toBe(['album' => 3])
            ->and($rows[1]['context']['exception']['class'])->toBe(RuntimeException::class)
            ->and($rows[1]['context']['exception']['message'])->toBe('disk full')
            ->and($rows[1]['origin']['file'])->toContain('LogLensTest.php');

        $summary = app(LogLens::class)->summarize($capture);

        expect($summary->totals()['log_captures'])->toBe(3)
            ->and($summary->totals()['log_by_level'])->toBe(['info' => 1, 'error' => 1, 'debug' => 1])
            ->and($summary->warnings())->toBe(['Logged error: Import failed']);
    });

    it('summarizes nothing without log rows', function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        expect(app(LogLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(new \ExeQue\Monocle\Data\Summary());
    });
});
