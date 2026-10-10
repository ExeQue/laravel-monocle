<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\EntryType;
use ExeQue\Monocle\Exceptions\LensAlreadyBootedException;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Facades\Monocle;
use ExeQue\Monocle\Lenses\CacheLens;
use ExeQue\Monocle\Lenses\DatabaseLens;
use ExeQue\Monocle\LensRegistry;
use Tests\Fixtures\CounterEvent;
use Tests\Fixtures\CounterLens;

covers(LensRegistry::class);

describe(LensRegistry::class, function (): void {
    beforeEach(function (): void {
        CounterLens::clear();
    });

    $freshRegistry = function (array $lenses): LensRegistry {
        config(['monocle.lenses' => $lenses]);

        return new LensRegistry(app());
    };

    it('boots lenses from config, passes options and ignores disabled ones', function () use ($freshRegistry): void {
        $registry = $freshRegistry([CounterLens::class => ['threshold' => 2], DatabaseLens::class => false, CacheLens::class => true]);
        $registry->boot(app(Eyepiece::class));

        expect(CounterLens::$booted)->toBe(1)
            ->and(CounterLens::$options)->toBe(['threshold' => 2])
            ->and(array_map(fn ($lens): string => $lens::class, $registry->booted()))->toBe([CounterLens::class, CacheLens::class])
            ->and($registry->descriptions())->toHaveKey('counter', 'Counts CounterEvent dispatches.');
    });

    it('lets facade registrations override config and runs finalize/reset', function () use ($freshRegistry): void {
        $registry = $freshRegistry([CounterLens::class => false]);
        $this->app->instance(LensRegistry::class, $registry);

        Monocle::register(CounterLens::class, ['from' => 'facade']);
        Monocle::disable(CacheLens::class);

        expect($registry->lenses())->toBe([CounterLens::class => ['from' => 'facade'], CacheLens::class => false]);

        $registry->boot(app(Eyepiece::class));
        app(Eyepiece::class)->start(EntryType::Request);
        event(new CounterEvent(1));
        event(new CounterEvent(2));
        $entry = app(Eyepiece::class)->finalize();

        expect($entry->rows('counter'))->toHaveCount(2)
            ->and($entry->sections['counter_meta'])->toBe(['finalized' => true])
            ->and(CounterLens::$finalized)->toBe(1)
            ->and(CounterLens::$reset)->toBeGreaterThanOrEqual(1);
    });

    it('enable restores the configured options', function () use ($freshRegistry): void {
        $registry = $freshRegistry([CounterLens::class => ['x' => 1]]);
        $registry->disable(CounterLens::class);
        $registry->enable(CounterLens::class);

        expect($registry->lenses()[CounterLens::class])->toBe(['x' => 1]);
    });

    it('refuses registrations after boot', function () use ($freshRegistry): void {
        $registry = $freshRegistry([]);
        $registry->boot(app(Eyepiece::class));

        expect(fn () => $registry->register(CounterLens::class))->toThrow(LensAlreadyBootedException::class);
    });

    it('exposes summarizers', function () use ($freshRegistry): void {
        $registry = $freshRegistry([CounterLens::class => true]);
        $registry->boot(app(Eyepiece::class));

        expect($registry->summarizers())->toHaveCount(1);
    });
});
