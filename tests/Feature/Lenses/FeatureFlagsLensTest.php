<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Pennant\FeatureFlagsLens;
use Laravel\Pennant\Events\FeatureDeleted;
use Laravel\Pennant\Events\FeatureResolved;
use Laravel\Pennant\Events\FeatureRetrieved;
use Laravel\Pennant\Events\FeatureUpdated;
use Laravel\Pennant\Events\UnknownFeatureResolved;

covers(FeatureFlagsLens::class);

describe(FeatureFlagsLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('records every flag check with its scope, value and where the value came from', function (): void {
        event(new FeatureRetrieved('new-checkout', null, true));
        event(new FeatureResolved('beta-dashboard', 'team:4', 'variant-b'));
        event(new UnknownFeatureResolved('new-chekout', null));
        event(new FeatureUpdated('beta-dashboard', 'team:4', false));
        event(new FeatureDeleted('old-flag', null));

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('features');
        $summary = (new FeatureFlagsLens())->summarize($capture);

        expect(array_map(fn (array $row): array => [$row['feature'], $row['scope'], $row['value'], $row['source']], $rows))->toBe([
            ['new-checkout', null, true, 'stored'],
            ['beta-dashboard', 'team:4', 'variant-b', 'resolved'],
            ['new-chekout', null, null, 'unknown'],
            ['beta-dashboard', 'team:4', false, 'updated'],
            ['old-flag', null, null, 'deleted'],
        ])
            ->and($summary['totals'])->toBe(['features_checked' => 4, 'features_resolved' => 1])
            ->and($summary['warnings'])->toBe(['Feature new-chekout is not defined; it resolved to false.']);
    });

    it('summarizes nothing without flag checks', function (): void {
        expect((new FeatureFlagsLens())->summarize(app(Eyepiece::class)->finalize()))->toBe([]);
    });
});
