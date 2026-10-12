<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use ExeQue\Monocle\Lenses\FilesystemLens;
use ExeQue\Monocle\Lenses\HttpClientLens;
use ExeQue\Monocle\Support\Masks\FullMask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Folio\Folio;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Prism\Prism\PrismManager;
use RuntimeException;
use Workbench\App\Cache\SlowStore;
use Workbench\App\Console\Commands\FailingCommand;
use Workbench\App\Console\Commands\GreetCommand;
use Workbench\App\Events\PostPublished;
use Workbench\App\Listeners\NotifyFollowers;
use Workbench\App\Livewire\BrokenWidget;
use Workbench\App\Livewire\Counter;
use Workbench\App\Models\User;
use Workbench\App\Monocle\PaymentsLens;
use Workbench\App\Prism\DemoProvider;

final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $database = dirname(__DIR__, 2).'/database/database.sqlite';

        if (! is_file($database)) {
            touch($database);
        }

        $storage = dirname(__DIR__, 2).'/storage';

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $database,
            // A cache store that takes 30 ms per call, for slow cache warnings.
            'cache.stores.slow' => ['driver' => 'slow'],
            'filesystems.disks.demo' => ['driver' => 'local', 'root' => $storage.'/demo'],
            'filesystems.disks.media' => ['driver' => 'local', 'root' => $storage.'/media'],
            'media-library.disk_name' => 'media',
            'media-library.queue_conversions_by_default' => false,
            'monocle.lenses.'.FilesystemLens::class => ['disks' => ['demo']],
            // An application lens with its own dashboard tab.
            'monocle.lenses.'.PaymentsLens::class => true,
            // The queue worker and schedule demos only start a process; the jobs and commands it runs are captures of their own.
            'monocle.except.paths' => [...(array) config('monocle.except.paths', []), '/', 'favicon.ico', 'demo/queue/work', 'demo/alarms/failed-scheduled-task'],
            // The showcase sends an API key to an outgoing API; mask it for the http lens only.
            'monocle.mask.headers' => [...(array) config('monocle.mask.headers', []), HttpClientLens::class => ['x-api-key' => FullMask::class]],
            'monocle.mask.inputs' => [...(array) config('monocle.mask.inputs', []), HttpClientLens::class => '/signature/i'],
            'monocle.except.commands' => [...(array) config('monocle.except.commands', []), 'workbench:*', 'package:purge-skeleton', 'migrate*', 'db:seed'],
            // Demo pages link the fetch and XHR calls they make (Livewire updates among them) to the page.
            'monocle.trace.browser.enabled' => true,
        ]);
    }

    public function boot(): void
    {
        $this->commands([GreetCommand::class, FailingCommand::class]);

        // A scheduled task that always fails, for schedule:run.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(fn () => throw new RuntimeException('The nightly export could not reach the warehouse.'))
                ->name('nightly-export')
                ->everyMinute();
        });

        Cache::extend('slow', fn (): \Illuminate\Contracts\Cache\Repository => Cache::repository(new SlowStore(), ['store' => 'slow']));

        Event::listen(PostPublished::class, NotifyFollowers::class);

        // A Prism provider that runs the tools it is offered, so the demo shows Prism tool calls.
        $this->app->make(PrismManager::class)->extend('workbench', fn (): DemoProvider => new DemoProvider());

        Gate::define('read-docs', fn (?User $user): bool => true);
        Gate::define('delete-everything', fn (?User $user): bool => false);

        Feature::define('new-dashboard', true);
        Feature::define('beta-checkout', fn (?User $user): bool => $user !== null);

        Livewire::component('counter', Counter::class);
        Livewire::component('broken-widget', BrokenWidget::class);

        Inertia::setRootView('inertia');
        Inertia::share('app', ['name' => 'Monocle workbench']);
        Inertia::share('flash', Inertia::always(['status' => null]));

        Folio::path(dirname(__DIR__, 2).'/resources/views/pages')->uri('/folio');
    }
}
