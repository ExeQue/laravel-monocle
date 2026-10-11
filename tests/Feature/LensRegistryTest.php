<?php

declare(strict_types=1);

namespace Tests\Feature;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Exceptions\LensAlreadyBootedException;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Facades\Monocle;
use ExeQue\Monocle\Lenses\AuthLens;
use ExeQue\Monocle\Lenses\CacheLens;
use ExeQue\Monocle\Lenses\DatabaseLens;
use ExeQue\Monocle\Lenses\Packages\Lab404\ImpersonateLens;
use ExeQue\Monocle\LensRegistry;
use Tests\Fixtures\CounterEvent;
use Tests\Fixtures\CounterLens;
use Tests\Fixtures\LedgerLens;
use Tests\Fixtures\MissingPackageLens;
use Tests\Fixtures\PaymentsLens;

covers(LensRegistry::class, LensAlreadyBootedException::class);

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

    it('runs an extension only with the lens it extends, on that lens\'s section', function () use ($freshRegistry): void {
        expect($freshRegistry([ImpersonateLens::class => true])->enabled())->toBe([]);

        $registry = $freshRegistry([AuthLens::class => true, ImpersonateLens::class => true]);
        $registry->boot(app(Eyepiece::class));

        expect($registry->enabled())->toBe([AuthLens::class, ImpersonateLens::class])
            ->and($registry->sectionOf(app(ImpersonateLens::class)))->toBe('auth')
            ->and($registry->descriptions())->not->toHaveKey('auth_impersonate')
            ->and($registry->descriptions()['auth'])->toContain('lab404/laravel-impersonate')
            ->and($registry->advice())->toHaveKey('auth')->not->toHaveKey('auth_impersonate');
    });

    it('leaves out lenses whose packages are not installed', function () use ($freshRegistry): void {
        MissingPackageLens::$booted = 0;
        $registry = $freshRegistry([MissingPackageLens::class => true, CacheLens::class => true]);
        $registry->boot(app(Eyepiece::class));

        expect(MissingPackageLens::$booted)->toBe(0)
            ->and($registry->enabled())->toBe([CacheLens::class])
            ->and($registry->descriptions())->not->toHaveKey('missing')
            ->and(LensRegistry::available(MissingPackageLens::class))->toBeFalse()
            ->and(LensRegistry::available(CacheLens::class))->toBeTrue();
    });

    it('lets facade registrations override config and runs finalize/reset', function () use ($freshRegistry): void {
        $registry = $freshRegistry([CounterLens::class => false]);
        $this->app->instance(LensRegistry::class, $registry);

        Monocle::register(CounterLens::class, ['from' => 'facade']);
        Monocle::disable(CacheLens::class);

        expect($registry->lenses())->toBe([CounterLens::class => ['from' => 'facade'], CacheLens::class => false]);

        $registry->boot(app(Eyepiece::class));
        app(Eyepiece::class)->start(CaptureType::Request);
        event(new CounterEvent(1));
        event(new CounterEvent(2));
        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('counter'))->toHaveCount(2)
            ->and($capture->sections['counter_meta'])->toBe(['finalized' => true])
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

    it('boots only once', function () use ($freshRegistry): void {
        $registry = $freshRegistry([CounterLens::class => true]);

        expect($registry->isBooted())->toBeFalse();

        $registry->boot(app(Eyepiece::class));
        $registry->boot(app(Eyepiece::class));

        expect($registry->isBooted())->toBeTrue()
            ->and(CounterLens::$booted)->toBe(1)
            ->and($registry->booted())->toHaveCount(1);
    });

    it('finds the section of an extension whose lens has not booted', function () use ($freshRegistry): void {
        expect($freshRegistry([])->sectionOf(app(ImpersonateLens::class)))->toBe('auth');
    });

    it('collects presentations, letting a section\'s own lens win over an included one', function () use ($freshRegistry): void {
        $registry = $freshRegistry([CounterLens::class => true, PaymentsLens::class => true, LedgerLens::class => true]);
        $registry->boot(app(Eyepiece::class));

        $presentations = $registry->presentations();

        expect(array_keys($presentations))->toEqualCanonicalizing(['payments', 'ledger_notes', 'ledger'])
            ->and($presentations['payments']->label)->toBe('Payments')
            ->and($presentations['ledger_notes']->label)->toBe('Notes')
            ->and($presentations['ledger']->label)->toBe('Ledger');
    });

    it('advises with the whole description when it has no sentence end', function () use ($freshRegistry): void {
        expect($freshRegistry([LedgerLens::class => true, CounterLens::class => true])->advice())
            ->toBe(['ledger' => 'Ledger entries', 'counter' => 'Counts CounterEvent dispatches.']);
    });
});
