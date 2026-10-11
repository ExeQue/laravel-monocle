<?php

declare(strict_types=1);

namespace Tests\Unit\Reader;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Reader\RowQuery;
use ExeQue\Monocle\Reader\SectionReader;

covers(SectionReader::class);

describe(SectionReader::class, function (): void {
    $capture = function (): Capture {
        $capture = new Capture('c1', CaptureType::Request, microtime(true));
        $capture->sections = ['queries' => [['n' => 1], ['n' => 2]], 'request' => ['method' => 'GET']];

        return $capture;
    };

    it('names the sections a capture has', function () use ($capture): void {
        expect(new SectionReader()->sections($capture()))->toBe(['queries', 'request']);
    });

    it('pages a section of rows and returns a data section whole', function () use ($capture): void {
        $reader = new SectionReader();

        expect($reader->read($capture(), 'request', RowQuery::fromArray([])))->toBe(['section' => 'request', 'data' => ['method' => 'GET']])
            ->and($reader->read($capture(), 'queries', RowQuery::fromArray(['limit' => 1])))->toMatchArray(['section' => 'queries', 'total' => 2, 'returned' => 1]);
    });
});
