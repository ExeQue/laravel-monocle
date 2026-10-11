<?php

declare(strict_types=1);

namespace Tests\Unit\Reader;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Reader\TimelineBuilder;

covers(TimelineBuilder::class);

describe(TimelineBuilder::class, function (): void {
    it('says how a span ended unless it was stopped normally', function (): void {
        $capture = new Capture('c1', CaptureType::Request, microtime(true));

        foreach (['stopped' => 'stop', 'cut' => 'capture-end'] as $id => $endedBy) {
            $span = new Span($id, $id, 'user', null, null, null);
            $span->start = 1.0;
            $span->end = 2.0;
            $span->endedBy = $endedBy;
            $capture->spans[$id] = $span;
        }

        $rows = array_column((new TimelineBuilder())->build($capture), null, 'id');

        expect($rows['stopped'])->not->toHaveKey('ended_by')
            ->and($rows['cut']['ended_by'])->toBe('capture-end');
    });
});
