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

        expect($summary->totals['log_captures'])->toBe(3)
            ->and($summary->totals['log_by_level'])->toBe(['info' => 1, 'error' => 1, 'debug' => 1])
            ->and($summary->errors)->toBe(['Logged error: Import failed']);
    });

    it('summarizes nothing without log rows', function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        expect(app(LogLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(\ExeQue\Monocle\Data\Summary::make());
    });

    it('ignores log messages written while not recording', function (): void {
        config(['logging.default' => 'null']);

        Log::info('Outside any capture');

        app(Eyepiece::class)->start(CaptureType::Request);

        expect(app(Eyepiece::class)->finalize()->rows('log'))->toBe([]);
    });

    it('does not record its own failure reports', function (): void {
        config(['logging.default' => 'null']);
        app(Eyepiece::class)->start(CaptureType::Request);

        Log::warning('Monocle failed to record a row');
        Log::warning('Something else');

        expect(array_column(app(Eyepiece::class)->finalize()->rows('log'), 'message'))->toBe(['Something else']);
    });

    it('presents the log as a table grouped by message', function (): void {
        $presentation = app(LogLens::class)->presentation();

        expect($presentation->tabView)->toBe('monocle::tabs.log')
            ->and($presentation->groupings)->toBe(['message' => 'by message'])
            ->and($presentation->formats)->toBe(['level' => ['level'], 'context' => ['fields']]);
    });
});
