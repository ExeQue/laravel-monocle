<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use ExeQue\Monocle\Data\Summary;

covers(Summary::class);

describe(Summary::class, function (): void {
    it('collects totals and warnings fluently', function (): void {
        $summary = Summary::make()
            ->total('payments', 2)
            ->total('payments_declined', 1)
            ->warning('Payment declined.');

        expect($summary->totals())->toBe(['payments' => 2, 'payments_declined' => 1])
            ->and($summary->warnings())->toBe(['Payment declined.']);
    });

    it('takes several warnings at once', function (): void {
        expect(Summary::make()->warning(['a', 'b'])->warning('c')->warnings())->toBe(['a', 'b', 'c']);
    });

    it('takes totals and warnings up front too', function (): void {
        $summary = (new Summary(totals: ['a' => 1], warnings: ['w']))->total('b', 2);

        expect($summary->totals())->toBe(['a' => 1, 'b' => 2])
            ->and($summary->warnings())->toBe(['w']);
    });
});
