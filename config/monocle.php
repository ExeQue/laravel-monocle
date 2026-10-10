<?php

declare(strict_types=1);

use ExeQue\Monocle\Lenses\AiLens;
use ExeQue\Monocle\Lenses\AuthorizationLens;
use ExeQue\Monocle\Lenses\CacheLens;
use ExeQue\Monocle\Lenses\CommandLens;
use ExeQue\Monocle\Lenses\DatabaseLens;
use ExeQue\Monocle\Lenses\DumpLens;
use ExeQue\Monocle\Lenses\EloquentLens;
use ExeQue\Monocle\Lenses\EventsLens;
use ExeQue\Monocle\Lenses\ExceptionsLens;
use ExeQue\Monocle\Lenses\FilesystemLens;
use ExeQue\Monocle\Lenses\HttpClientLens;
use ExeQue\Monocle\Lenses\LivewireLens;
use ExeQue\Monocle\Lenses\LogLens;
use ExeQue\Monocle\Lenses\MailLens;
use ExeQue\Monocle\Lenses\McpLens;
use ExeQue\Monocle\Lenses\MiddlewareLens;
use ExeQue\Monocle\Lenses\QueueLens;
use ExeQue\Monocle\Lenses\RedisLens;
use ExeQue\Monocle\Lenses\RequestLens;
use ExeQue\Monocle\Lenses\ScheduleLens;
use ExeQue\Monocle\Lenses\SessionLens;
use ExeQue\Monocle\Lenses\ViewsLens;
use ExeQue\Monocle\Support\Masks\PartialMask;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When null, Monocle records only outside production and only with debug mode
    | turned on. Set MONOCLE_ENABLED to force it, or decide at runtime from a
    | service provider: Monocle::enabled(fn () => ! app()->isProduction()).
    | When disabled nothing is hooked, so the package is inert in production.
    | Monocle never records inside the unit test runner unless explicitly true.
    |
    */

    'enabled' => env('MONOCLE_ENABLED'),

    /*
    |--------------------------------------------------------------------------
    | Lenses
    |--------------------------------------------------------------------------
    |
    | Every lens is a discrete class that hooks into the framework on its own.
    | Map a class to true to run it with defaults, false to disable it, or an
    | array of options the lens understands. Add your own lenses here or call
    | Monocle::register() from a service provider.
    |
    */

    'lenses' => [
        RequestLens::class => [
            'response_types' => ['application/json', 'application/*+json'],
        ],
        SessionLens::class => true,
        CommandLens::class => true,
        QueueLens::class => true,
        ScheduleLens::class => true,
        MiddlewareLens::class => true,
        DatabaseLens::class => [
            'slow_ms' => 70,
            'n_plus_one_threshold' => 3,
            'bindings' => true,
        ],
        CacheLens::class => [
            'values' => false,
            'repeat_threshold' => 5,
            'repeat_ms' => 5,
        ],
        EloquentLens::class => true,
        RedisLens::class => true,
        EventsLens::class => [
            'ignore' => ['Illuminate\*', 'Laravel\*', 'Livewire\*', 'eloquent.*', 'composing:*', 'creating:*', 'bootstrapping:*', 'bootstrapped:*', 'locale.*'],
        ],
        LogLens::class => true,
        ViewsLens::class => [
            'engines' => ['blade', 'php', 'file'],
            'data' => false,
            'repeat_threshold' => 10,
            'repeat_ms' => 20,
        ],
        MailLens::class => true,
        HttpClientLens::class => [
            'bodies' => false,
        ],
        ExceptionsLens::class => true,
        AuthorizationLens::class => true,
        LivewireLens::class => true,
        DumpLens::class => true,
        McpLens::class => true,
        FilesystemLens::class => [
            'disks' => [],                          // disk names, wildcards allowed: ['media', 'uploads-*'] or ['*']
        ],
        AiLens::class => [
            'max_text' => null,                     // null = never truncate prompts and responses
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Except
    |--------------------------------------------------------------------------
    |
    | Requests whose path matches one of these patterns, and console commands
    | whose name matches, are never recorded. Monocle's own MCP server is
    | listed here; the dashboard is excluded on its own.
    |
    */

    'except' => [
        'paths' => ['__clockwork', '__clockwork/*', '_debugbar/*', '_boost/browser-logs', 'broadcasting/auth', 'horizon', 'horizon/*'],
        'commands' => ['monocle:*', 'mcp:start monocle', 'boost:*', 'queue:work', 'queue:listen', 'horizon*', 'schedule:work'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Masking
    |--------------------------------------------------------------------------
    |
    | Header names (lowercase) whose values are masked before storing, and a
    | regular expression matched against input keys whose values are masked.
    | `using` is an invokable class receiving ($value, $key) and returning the
    | masked value; the default keeps the first four characters visible.
    |
    */

    'mask' => [
        'headers' => ['authorization', 'cookie', 'set-cookie', 'x-xsrf-token', 'x-csrf-token'],
        'inputs' => '/pass|token|secret|authorization/i',
        'using' => PartialMask::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Origin
    |--------------------------------------------------------------------------
    |
    | Every recorded row carries the user-land file and line that triggered
    | it, plus a short call chain. Frames inside vendor/ are dropped unless
    | the path starts with one of the include_vendor prefixes.
    |
    */

    'origin' => [
        'enabled' => true,
        'frames' => 5,
        'include_vendor' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Captures are kept in a ring buffer holding the newest `limit` captures.
    | The driver may be "file", "cache", a class implementing the Store
    | contract, or a name registered with Monocle::extendStore().
    |
    */

    'storage' => [
        'driver' => env('MONOCLE_STORAGE', 'file'),
        'limit' => 200,
        'file' => [
            // Must be inside storage_path(); the file store never operates
            // outside the application's storage directory.
            'path' => storage_path('monocle'),
        ],
        'cache' => [
            'store' => null,
            'ttl' => 86400,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP
    |--------------------------------------------------------------------------
    |
    | Monocle exposes its own local MCP server for AI agents. Servers named in
    | except_servers are never recorded by the McpLens.
    |
    */

    'mcp' => [
        'enabled' => true,
        'server' => 'monocle',
        'except_servers' => ['monocle', 'laravel-boost'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | The web dashboard and its JSON API live under `path` (and `domain`, when
    | set). Access is decided by the viewMonocle gate, which by default only
    | allows the local environment; define the gate yourself to change that.
    | Requests to the dashboard are never recorded.
    |
    */

    'dashboard' => [
        'enabled' => env('MONOCLE_DASHBOARD', true),
        'path' => env('MONOCLE_PATH', '_monocle'),
        'domain' => env('MONOCLE_DOMAIN'),
        'middleware' => ['web'],
    ],

];
