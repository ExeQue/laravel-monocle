<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Pennant;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use Laravel\Pennant\Events\FeatureDeleted;
use Laravel\Pennant\Events\FeatureResolved;
use Laravel\Pennant\Events\FeatureRetrieved;
use Laravel\Pennant\Events\FeatureUpdated;
use Laravel\Pennant\Events\UnknownFeatureResolved;

/**
 * Laravel Pennant feature checks: which flags a request looked at, for which
 * scope, the value it got and whether that came from storage or was resolved
 * just now. Explains why a request took one branch rather than another.
 */
final class FeatureFlagsLens implements Advises, Lens, Presentable, RequiresPackages, Summarizable
{
    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'features';
    }

    public static function packages(): array
    {
        return ['laravel/pennant'];
    }

    public function description(): string
    {
        return 'Laravel Pennant feature flags: feature, scope (models as Class#id, null for global), value, and source - stored (read from storage), resolved (worked out now and stored), unknown (no such feature defined), updated or deleted.';
    }

    public function advice(): string
    {
        return 'Look here when a request took an unexpected branch: which feature flags it checked and the value it got.';
    }

    public function boot(): void
    {
        $events = [
            FeatureRetrieved::class => 'stored',
            FeatureResolved::class => 'resolved',
            UnknownFeatureResolved::class => 'unknown',
            FeatureUpdated::class => 'updated',
            FeatureDeleted::class => 'deleted',
        ];

        foreach ($events as $event => $source) {
            if (! class_exists($event)) {
                continue;
            }

            $this->eyepiece->listen($event, function (object $event) use ($source): void {
                if (! $this->eyepiece->recording()) {
                    return;
                }

                $this->eyepiece->push('features', [
                    'feature' => is_string($event->feature ?? null) ? $event->feature : Normalizer::value($event->feature ?? null),
                    'scope' => Normalizer::value($event->scope ?? null),
                    'value' => property_exists($event, 'value') ? Normalizer::value($event->value) : null,
                    'source' => $source,
                ]);
            });
        }
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('features');

        if ($rows === []) {
            return Summary::make();
        }

        $unknown = array_values(array_unique(array_column(array_filter($rows, fn (array $row): bool => $row['source'] === 'unknown'), 'feature')));

        return Summary::make()
            ->total('features_checked', count(array_unique(array_column($rows, 'feature'))), 'checked')
            ->total('features_resolved', count(array_filter($rows, fn (array $row): bool => $row['source'] === 'resolved')), 'resolved')
            ->warnings(array_map(fn (string $feature): string => "Feature {$feature} is not defined; it resolved to false.", $unknown));
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.features')
            ->groupBy(['feature' => 'by feature'])
            ->format(['value' => 'bool', 'source' => ['highlight', ['unknown'], 'warn']]);
    }
}
