<?php

declare(strict_types=1);

namespace ExeQue\Monocle;

use ExeQue\Monocle\Console\ClearCommand;
use ExeQue\Monocle\Console\InstallCommand;
use ExeQue\Monocle\Console\ListCommand;
use ExeQue\Monocle\Console\QueriesCommand;
use ExeQue\Monocle\Console\RowsCommand;
use ExeQue\Monocle\Console\ShowCommand;
use ExeQue\Monocle\Console\TraceCommand;
use ExeQue\Monocle\Mcp\MonocleServer;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;

final class MonocleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/lens.php', 'monocle');

        $this->app->singleton(StoreManager::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(LensRegistry::class);
        $this->app->singleton(MonocleManager::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/lens.php' => $this->app->configPath('monocle.php')], 'monocle-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ClearCommand::class,
                ListCommand::class,
                ShowCommand::class,
                RowsCommand::class,
                QueriesCommand::class,
                TraceCommand::class,
            ]);
        }

        $this->registerMcpServer();

        /** @var Recorder $recorder */
        $recorder = $this->app->make(Recorder::class);

        if (! $recorder->enabled()) {
            return;
        }

        $this->app->make(LensRegistry::class)->boot($recorder);

        register_shutdown_function(static function () use ($recorder): void {
            $recorder->finalize('shutdown');
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
