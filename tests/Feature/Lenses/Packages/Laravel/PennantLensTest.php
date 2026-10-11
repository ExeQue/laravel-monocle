<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Laravel;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Laravel\PennantLens;
use Laravel\Pennant\Feature;

covers(PennantLens::class);

describe(PennantLens::class, function (): void {
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
        $summary = app(PennantLens::class)->summarize($capture);

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

    it('ignores flag checks made while nothing is recording', function (): void {
        app(Eyepiece::class)->reset();
        Feature::define('quiet-flag', fn (): bool => true);

        Feature::active('quiet-flag');

        app(Eyepiece::class)->start(CaptureType::Request);

        expect(app(Eyepiece::class)->finalize()->rows('features'))->toBe([]);
    });

    it('presents flags grouped by feature with unknown sources highlighted', function (): void {
        $presentation = app(PennantLens::class)->presentation();

        expect($presentation->tabView)->toBe('monocle::tabs.features')
            ->and($presentation->groupings)->toBe(['feature' => 'by feature'])
            ->and($presentation->formats)->toBe(['value' => ['bool'], 'source' => ['highlight', ['unknown'], 'warn']]);
    });

    it('describes the feature rows and when to look at them', function (): void {
        $lens = app(PennantLens::class);

        expect($lens->description())->toContain('stored', 'resolved', 'unknown')
            ->and($lens->advice())->toStartWith('Look here when a request took an unexpected branch');
    });

    it('summarizes nothing without flag checks', function (): void {
        expect(app(PennantLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(\ExeQue\Monocle\Data\Summary::make());
    });
});
