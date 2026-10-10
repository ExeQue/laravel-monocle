<?php

declare(strict_types=1);

namespace Tests;

use ExeQue\Monocle\MonocleServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected string $monoclePath;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, MonocleServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->monoclePath = sys_get_temp_dir() . '/monocle-tests-' . bin2hex(random_bytes(4));

        $app['config']->set('monocle.enabled', true);
        $app['config']->set('monocle.storage.file.path', $this->monoclePath);
        $app['config']->set('monocle.storage.limit', 10);
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

        parent::tearDown();
    }
}
