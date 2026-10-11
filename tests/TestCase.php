<?php

declare(strict_types=1);

namespace Tests;

use ExeQue\Monocle\MonocleServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\AiServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected string $monoclePath;

    /**
     * Config applied before the providers boot; set it and call
     * refreshApplication() to test boot-time behaviour.
     *
     * @var array<string, mixed>
     */
    public static array $bootConfig = [];

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            McpServiceProvider::class,
            AiServiceProvider::class,
            LivewireServiceProvider::class,
            \Laravel\Pennant\PennantServiceProvider::class,
            \Spatie\ResponseCache\ResponseCacheServiceProvider::class,
            \Spatie\MediaLibrary\MediaLibraryServiceProvider::class,
            \Laravel\Sanctum\SanctumServiceProvider::class,
            \Laravel\Fortify\FortifyServiceProvider::class,
            \Laravel\Folio\FolioServiceProvider::class,
            \Inertia\ServiceProvider::class,
            MonocleServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->monoclePath = $app->storagePath('monocle-tests-'.bin2hex(random_bytes(4)));

        $app['config']->set('monocle.enabled', true);
        $app['config']->set('monocle.throw_failures', true);
        $app['config']->set('monocle.storage.file.path', $this->monoclePath);
        $app['config']->set('monocle.lenses.'.\ExeQue\Monocle\Lenses\FilesystemLens::class, ['disks' => ['spy', 'wild-*']]);
        $app['config']->set('monocle.storage.limit', 10);
        foreach (self::$bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->monoclePath);
        self::$bootConfig = [];

        parent::tearDown();
    }
}
