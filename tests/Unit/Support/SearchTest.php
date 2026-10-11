<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use ExeQue\Monocle\Support\Search;

covers(Search::class);

describe(Search::class, function (): void {
    $row = ['sql' => 'select * from "Users"', 'duration' => 12.5, 'slow' => true, 'origin' => ['file' => 'app/Orders.php']];

    it('matches everything without words', function () use ($row): void {
        expect(Search::matches($row, null))->toBeTrue()
            ->and(Search::matches($row, '  '))->toBeTrue();
    });

    it('needs every word in some value, ignoring case', function () use ($row): void {
        expect(Search::matches($row, 'users SELECT'))->toBeTrue()
            ->and(Search::matches($row, 'users posts'))->toBeFalse();
    });

    it('looks into nested values, numbers and booleans but not keys', function () use ($row): void {
        expect(Search::matches($row, 'orders.php'))->toBeTrue()
            ->and(Search::matches($row, '12.5'))->toBeTrue()
            ->and(Search::matches($row, 'true'))->toBeTrue()
            ->and(Search::matches($row, 'duration'))->toBeFalse();
    });
});
