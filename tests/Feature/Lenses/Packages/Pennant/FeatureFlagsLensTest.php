<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Pennant;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Pennant\FeatureFlagsLens;
use Laravel\Pennant\Feature;

covers(FeatureFlagsLens::class);

describe(FeatureFlagsLens::class, function (): void {
    beforeEach(function (): void {
        config(['pennant.default' => 'array', 'pennant.stores.array' => ['driver' => 'array']]);
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('records feature checks through Pennant with their value and where it came from', function (): void {
        Feature::define('new-checkout', fn (): bool => true);
        Feature::define('beta-dashboard', fn (): string => 'variant-b');

        Feature::active('new-checkout');
        Feature::value('beta-dashboard');
        Feature::active('new-chekout');
        Feature::activate('beta-dashboard', 'variant-c');
        Feature::forget('new-checkout');

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('features');
        $summary = app(FeatureFlagsLens::class)->summarize($capture);

        expect(array_map(fn (array $row): array => [$row['feature'], $row['source']], $rows))->toContain(
            ['new-checkout', 'resolved'],
            ['beta-dashboard', 'resolved'],
            ['new-chekout', 'unknown'],
            ['beta-dashboard', 'updated'],
            ['new-checkout', 'deleted'],
        )
            ->and(collect($rows)->firstWhere('source', 'resolved')['value'])->toBeTrue()
            ->and($summary->warnings)->toBe(['Feature new-chekout is not defined; it resolved to false.']);
    });

    it('summarizes nothing without flag checks', function (): void {
        expect(app(FeatureFlagsLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(new \ExeQue\Monocle\Data\Summary());
    });
});
