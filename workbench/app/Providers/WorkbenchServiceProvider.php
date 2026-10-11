<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use ExeQue\Monocle\Lenses\FilesystemLens;
use ExeQue\Monocle\Lenses\HttpClientLens;
use ExeQue\Monocle\Support\Masks\FullMask;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Folio\Folio;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Workbench\App\Console\Commands\GreetCommand;
use Workbench\App\Events\PostPublished;
use Workbench\App\Listeners\NotifyFollowers;
use Workbench\App\Livewire\Counter;
use Workbench\App\Models\User;

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
            'filesystems.disks.demo' => ['driver' => 'local', 'root' => $storage.'/demo'],
            'filesystems.disks.media' => ['driver' => 'local', 'root' => $storage.'/media'],
            'media-library.disk_name' => 'media',
            'media-library.queue_conversions_by_default' => false,
            'monocle.lenses.'.FilesystemLens::class => ['disks' => ['demo']],
            'monocle.except.paths' => [...(array) config('monocle.except.paths', []), '/', 'favicon.ico'],
            // The showcase sends an API key to an outgoing API; mask it for the http lens only.
            'monocle.mask.headers' => [...(array) config('monocle.mask.headers', []), HttpClientLens::class => ['x-api-key' => FullMask::class]],
            'monocle.mask.inputs' => [...(array) config('monocle.mask.inputs', []), HttpClientLens::class => '/signature/i'],
            'monocle.except.commands' => [...(array) config('monocle.except.commands', []), 'workbench:*', 'package:purge-skeleton', 'migrate*', 'db:seed'],
        ]);
    }

    public function boot(): void
    {
        $this->commands([GreetCommand::class]);

        Event::listen(PostPublished::class, NotifyFollowers::class);

        Gate::define('read-docs', fn (?User $user): bool => true);
        Gate::define('delete-everything', fn (?User $user): bool => false);

        Feature::define('new-dashboard', true);
        Feature::define('beta-checkout', fn (?User $user): bool => $user !== null);

        Livewire::component('counter', Counter::class);

        Inertia::setRootView('inertia');

        Folio::path(dirname(__DIR__, 2).'/resources/views/pages')->uri('/folio');
    }
}
