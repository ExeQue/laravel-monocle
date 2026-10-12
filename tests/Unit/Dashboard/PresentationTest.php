<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use ExeQue\Monocle\Dashboard\Presentation;

covers(Presentation::class);

describe(Presentation::class, function (): void {
    it('takes collections wherever it takes arrays', function (): void {
        $tools = Presentation::table()->label('Tools');
        $fromArrays = Presentation::table()
            ->columns(['tool', 'status' => 'Outcome'])
            ->detailsOnly(['arguments'])
            ->groupBy(['tool' => 'by tool'])
            ->includes(['auth', 'tools' => $tools])
            ->alongside(['job' => $tools])
            ->format(['status' => 'status', 'tool' => ['truncate', 20]])
            ->highlight(['repeat_of' => 'warn']);
        $fromCollections = Presentation::table()
            ->columns(collect(['tool', 'status' => 'Outcome']))
            ->detailsOnly(collect(['arguments']))
            ->groupBy(collect(['tool' => 'by tool']))
            ->includes(collect(['auth', 'tools' => $tools]))
            ->alongside(collect(['job' => $tools]))
            ->format(collect(['status' => 'status', 'tool' => ['truncate', 20]]))
            ->highlight(collect(['repeat_of' => 'warn']));

        expect($fromCollections)->toEqual($fromArrays)
            ->and($fromArrays->columns)->toBe(['tool' => null, 'status' => 'Outcome'])
            ->and($fromArrays->includes)->toBe(['auth', 'tools'])
            ->and($fromArrays->included)->toBe(['tools' => $tools, 'job' => $tools])
            ->and($fromArrays->formats)->toBe(['status' => ['status'], 'tool' => ['truncate', 20]]);
    });
});
