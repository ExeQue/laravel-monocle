<?php

declare(strict_types=1);

namespace Tests\Unit\Reader;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Reader\SummaryBuilder;

covers(SummaryBuilder::class);

describe(SummaryBuilder::class, function (): void {
    $span = function (string $id, string $kind, ?string $parent, float $ms): Span {
        $span = new Span($id, $id, $kind, $parent, null, null);
        $span->start = 1.0;
        $span->end = 1.0 + $ms;

        return $span;
    };

    it('times views once, leaving out views rendered inside another view', function () use ($span): void {
        $capture = new Capture('c1', CaptureType::Request, microtime(true));
        $capture->endedAt = $capture->startedAt + 1;

        foreach ([
            $span('controller', 'controller', null, 50),
            $span('page', 'view', 'controller', 40),
            $span('partial', 'view', 'page', 10),
            $span('orphan', 'view', 'gone', 5),
            $span('loose', 'view', null, 3),
        ] as $item) {
            $capture->spans[$item->id] = $item;
        }

        $views = array_column(app(SummaryBuilder::class)->build($capture, children: false)['breakdown']['categories'], null, 'name')['views'];

        expect($views['count'])->toBe(3)
            ->and($views['ms'])->toBe(48.0);
    });

    it('leaves peak memory out when it was not measured and warns about recording failures', function (): void {
        $capture = new Capture('c1', CaptureType::Request, microtime(true));
        $capture->endedAt = $capture->startedAt;
        $capture->health = ['failures' => [['message' => 'boom', 'file' => 'CacheLens.php']]];

        $summary = app(SummaryBuilder::class)->build($capture);

        expect($summary['totals']['memory_peak_mb'])->toBeNull()
            ->and($summary['warnings'])->toContain('Monocle failed to record part of this capture: boom (CacheLens.php).')
            ->and($summary['flags'])->toContain(['level' => 'warning', 'section' => 'monocle', 'message' => 'Monocle failed to record part of this capture: boom (CacheLens.php).']);
    });
});
