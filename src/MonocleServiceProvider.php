<?php

declare(strict_types=1);

namespace ExeQue\Monocle;

use ExeQue\Monocle\Console\ClearCommand;
use ExeQue\Monocle\Console\CompareCommand;
use ExeQue\Monocle\Console\EditorCommand;
use ExeQue\Monocle\Console\InstallCommand;
use ExeQue\Monocle\Console\ListCommand;
use ExeQue\Monocle\Console\QueriesCommand;
use ExeQue\Monocle\Console\RowsCommand;
use ExeQue\Monocle\Console\ShowCommand;
use ExeQue\Monocle\Console\TraceCommand;
use ExeQue\Monocle\Editor\EditorLinks;
use ExeQue\Monocle\Install\ConfigPublisher;
use ExeQue\Monocle\Mcp\MonocleServer;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;

final class MonocleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monocle.php', 'monocle');

        $this->app->singleton(StoreManager::class);
        $this->app->bind(ConfigPublisher::class, fn (): ConfigPublisher => new ConfigPublisher(__DIR__.'/../config/monocle.php'));
        $this->app->singleton(Eyepiece::class);
        $this->app->singleton(LensRegistry::class);
        $this->app->singleton(MonocleManager::class);
        $this->app->singleton(EditorLinks::class, fn (Application $app): EditorLinks => EditorLinks::forProject($app->basePath()));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/monocle.php' => $this->app->configPath('monocle.php')], 'monocle-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                EditorCommand::class,
                ClearCommand::class,
                ListCommand::class,
                ShowCommand::class,
                RowsCommand::class,
                QueriesCommand::class,
                TraceCommand::class,
                CompareCommand::class,
            ]);
        }

        $this->registerMcpServer();

        /** @var Eyepiece $eyepiece */
        $eyepiece = $this->app->make(Eyepiece::class);

        if (! $eyepiece->enabled()) {
            return;
        }

        $this->app->make(LensRegistry::class)->boot($eyepiece);

        register_shutdown_function(static function () use ($eyepiece): void {
            $eyepiece->finalize('shutdown');
        });
    }

    private function registerMcpServer(): void
    {
        if (! $this->app->make('config')->get('monocle.mcp.enabled', true)) {
            return;
        }

        if (! class_exists(Registrar::class)) {
            return;
        }

        Mcp::local((string) $this->app->make('config')->get('monocle.mcp.server', 'monocle'), MonocleServer::class);
    }
}
