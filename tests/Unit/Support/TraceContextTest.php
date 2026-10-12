<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use ExeQue\Monocle\Support\TraceContext;

covers(TraceContext::class);

describe(TraceContext::class, function (): void {
    $parent = '019a0000-0000-7000-8000-00000000000A';
    $trace = '019a0000-0000-7000-8000-000000000002';

    it('accepts only UUIDv7 strings as capture ids', function (mixed $value, bool $valid): void {
        expect(TraceContext::isCaptureId($value))->toBe($valid);
    })->with([
        'a UUIDv7' => [$parent, true],
        'a UUIDv4' => ['9b2f1c1e-8a7d-4c1e-9f3a-2d5e6f7a8b9c', false],
        'garbage' => ['nope', false],
        'not a string' => [42, false],
        'null' => [null, false],
    ]);

    it('links only when both ids are capture ids, lowercased', function () use ($parent, $trace): void {
        expect(TraceContext::link($parent, $trace))->toBe(['parent_id' => strtolower($parent), 'trace_id' => $trace])
            ->and(TraceContext::link('nope', $trace))->toBeNull()
            ->and(TraceContext::link($parent, false))->toBeNull();
    });
});
