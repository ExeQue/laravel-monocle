<?php

declare(strict_types=1);

namespace Tests\Unit\Reader;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Reader\QueryAggregator;

covers(QueryAggregator::class);

describe(QueryAggregator::class, function (): void {
    it('groups queries without an origin under an unknown caller', function (): void {
        $capture = new Capture('c1', CaptureType::Request, microtime(true));
        $capture->sections = ['queries' => [
            ['n' => 1, 'sql' => 'select 1', 'duration' => 2.0],
            ['n' => 2, 'sql' => 'select 2', 'duration' => 1.0, 'origin' => ['file' => 'app/A.php', 'line' => 3]],
        ]];

        $groups = (new QueryAggregator())->group($capture, 'caller');

        expect(array_column($groups, 'key'))->toBe(['unknown', 'app/A.php:3']);
    });
});
