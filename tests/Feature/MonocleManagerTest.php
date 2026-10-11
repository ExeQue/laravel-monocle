<?php

declare(strict_types=1);

namespace Tests\Feature;

use ExeQue\Monocle\Contracts\Store;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Facades\Monocle;
use ExeQue\Monocle\Lenses\CacheLens;
use ExeQue\Monocle\LensRegistry;
use ExeQue\Monocle\MonocleManager;
use ExeQue\Monocle\Storage\CacheStore;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\CounterLens;

covers(MonocleManager::class, Monocle::class);

describe(MonocleManager::class, function (): void {
    beforeEach(function (): void {
        // A registry that has not booted yet, so runtime registration is allowed.
        $this->app->instance(LensRegistry::class, new LensRegistry($this->app));
        $this->app->forgetInstance(MonocleManager::class);
        Monocle::clearResolvedInstances();
    });

    it('registers, disables and enables lenses and is conditionable', function (): void {
        config(['monocle.lenses' => [CacheLens::class => ['values' => true]]]);

        Monocle::register(CounterLens::class, ['x' => 1])
            ->disable(CacheLens::class)
            ->when(false)->register('Nope')
            ->unless(false)->enable(CacheLens::class);

        expect(Monocle::lenses())->toBe([CacheLens::class => ['values' => true], CounterLens::class => ['x' => 1]]);
    });

    it('exposes the eyepiece, the active capture and store extension', function (): void {
        expect(Monocle::eyepiece())->toBeInstanceOf(Eyepiece::class)
            ->and(Monocle::active())->toBeNull();

        Monocle::eyepiece()->start(CaptureType::Request);

        expect(Monocle::active()?->type)->toBe(CaptureType::Request);

        config(['monocle.storage.driver' => 'custom']);
        Monocle::extendStore('custom', fn ($app, array $config): Store => new CacheStore(Cache::store('array'), 1, 60));
        app(StoreManager::class)->forget();

        expect(app(StoreManager::class)->store())->toBeInstanceOf(CacheStore::class);
    });

    it('toggles recording at runtime and reports it', function (): void {
        Monocle::enabled(false);

        expect(Monocle::isEnabled())->toBeFalse();

        Monocle::enabled(fn (): bool => true);

        expect(Monocle::isEnabled())->toBeTrue();
    });

    it('labels and annotates the active capture through the facade', function (): void {
        Monocle::eyepiece()->start(CaptureType::Request);
        Monocle::label('baseline')->meta(['k' => 'v']);
        Monocle::mark('point', ['n' => 1]);

        $capture = Monocle::eyepiece()->finalize();

        expect($capture->label)->toBe('baseline')
            ->and($capture->meta)->toBe(['k' => 'v'])
            ->and($capture->spansOfKind('mark')[0]->meta)->toBe(['n' => 1]);
    });
});
