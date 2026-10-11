<?php

declare(strict_types=1);

namespace Tests\Feature;

use ExeQue\Monocle\LensRegistry;
use ExeQue\Monocle\MonocleServiceProvider;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Registrar;
use Tests\TestCase;

covers(MonocleServiceProvider::class);

describe(MonocleServiceProvider::class, function (): void {
    it('boots the lenses only when Monocle is enabled', function (): void {
        expect(app(LensRegistry::class)->isBooted())->toBeTrue();

        TestCase::$bootConfig = ['monocle.enabled' => false];
        $this->refreshApplication();

        expect(app(LensRegistry::class)->isBooted())->toBeFalse();
    });

    it('registers the MCP server unless it is turned off', function (): void {
        expect(app(Registrar::class)->getLocalServer('monocle'))->not->toBeNull();

        TestCase::$bootConfig = ['monocle.mcp.enabled' => false];
        $this->refreshApplication();

        expect(app(Registrar::class)->getLocalServer('monocle'))->toBeNull();
    });

    it('leaves the dashboard routes to the route cache when routes are cached', function (): void {
        expect(Route::has('monocle.asset'))->toBeTrue();

        $cache = sys_get_temp_dir().'/monocle-routes-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($cache, '<?php');
        $_SERVER['APP_ROUTES_CACHE'] = $cache;

        try {
            $this->refreshApplication();

            expect($this->app->routesAreCached())->toBeTrue()
                ->and(Route::has('monocle.asset'))->toBeFalse();
        } finally {
            unset($_SERVER['APP_ROUTES_CACHE']);
            @unlink($cache);
        }
    });
});
