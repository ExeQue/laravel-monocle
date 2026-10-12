<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Exceptions\InvalidSummaryTotalException;
use ExeQue\Monocle\Support\Concerns\Immutable;

covers(Summary::class, InvalidSummaryTotalException::class, Immutable::class);

describe(Summary::class, function (): void {
    it('collects totals and warnings fluently', function (): void {
        $summary = Summary::make()
            ->total('payments', 2, 'payments')
            ->total('payments_declined', 1, 'declined')
            ->warning('Payment declined.');

        expect($summary->totals)->toBe(['payments' => 2, 'payments_declined' => 1])
            ->and($summary->labels)->toBe(['payments' => 'payments', 'payments_declined' => 'declined'])
            ->and($summary->warnings)->toBe(['Payment declined.']);
    });

    it('returns a new summary from every fluent method', function (): void {
        $empty = Summary::make();
        $summary = $empty->total('a', 1)->warning('w');

        expect($empty->totals)->toBe([])
            ->and($empty->warnings)->toBe([])
            ->and($summary)->not->toBe($empty)
            ->and($summary->totals)->toBe(['a' => 1]);
    });

    it('keeps the unit of a total, as a Unit or as a suffix', function (): void {
        $summary = Summary::make()
            ->total('query_ms', 8.7, 'spent', Unit::Milliseconds)
            ->total('charged', '49.00', 'charged', 'DKK')
            ->total('count', 3);

        expect($summary->units)->toBe(['query_ms' => 'ms', 'charged' => 'DKK']);
    });

    it('takes several totals at once in one unit', function (): void {
        $summary = Summary::make()->totals(['a' => 1, 'b' => null], Unit::Bytes)->total('c', 3);

        expect($summary->totals)->toBe(['a' => 1, 'b' => null, 'c' => 3])
            ->and($summary->units)->toBe(['a' => 'bytes', 'b' => 'bytes']);
    });

    it('forgets the label and unit when a total is set again without them', function (): void {
        $summary = Summary::make()->total('a', 1, 'first', Unit::Percent)->total('a', 2);

        expect($summary->totals)->toBe(['a' => 2])
            ->and($summary->labels)->toBe([])
            ->and($summary->units)->toBe([]);
    });

    it('refuses a single total without a value', function (): void {
        expect(fn () => Summary::make()->total('payments', null))->toThrow(InvalidSummaryTotalException::class);
    });

    it('takes several warnings at once', function (): void {
        expect(Summary::make()->warnings(['a', 'b'])->warning('c')->warnings)->toBe(['a', 'b', 'c']);
    });

    it('marks headline totals for the top of the overview', function (): void {
        $summary = Summary::make()->headline('response_bytes', 1024, 'response size', Unit::Bytes)->total('other', 1);

        expect($summary->headlines)->toBe(['response_bytes'])
            ->and($summary->totals)->toBe(['response_bytes' => 1024, 'other' => 1])
            ->and($summary->units)->toBe(['response_bytes' => 'bytes']);
    });

    it('tags the whole capture', function (): void {
        expect(Summary::make()->tag('impersonated', 'warn')->tag('replayed')->tags)->toBe([
            ['label' => 'impersonated', 'tone' => 'warn'],
            ['label' => 'replayed', 'tone' => 'muted'],
        ]);
    });

    it('keeps errors apart from warnings', function (): void {
        $summary = Summary::make()->warning('slow')->error('failed')->errors(['also failed']);

        expect($summary->warnings)->toBe(['slow'])
            ->and($summary->errors)->toBe(['failed', 'also failed']);
    });

    it('adds a total or a warning only when a condition holds', function (): void {
        $summary = Summary::make()
            ->when(false)->total('skipped', 1)
            ->when(true)->total('kept', 2)
            ->when(false)->warning('skipped');

        expect($summary->totals)->toBe(['kept' => 2])
            ->and($summary->warnings)->toBe([]);
    });

    it('takes collections for totals, warnings and errors', function (): void {
        $summary = Summary::make()
            ->totals(collect(['a' => 1, 'b' => 2]))
            ->warnings(collect(['slow']))
            ->errors(collect(['k' => 'broke']));

        expect($summary->totals)->toBe(['a' => 1, 'b' => 2])
            ->and($summary->warnings)->toBe(['slow'])
            ->and($summary->errors)->toBe(['broke']);
    });
});
