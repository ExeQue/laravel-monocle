<?php

declare(strict_types=1);

use ExeQue\Monocle\Lenses\AuthLens;
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
use ExeQue\Monocle\Lenses\LogLens;
use ExeQue\Monocle\Lenses\MailLens;
use ExeQue\Monocle\Lenses\MiddlewareLens;
use ExeQue\Monocle\Lenses\Packages\Ai\AiLens;
use ExeQue\Monocle\Lenses\Packages\Inertia\InertiaCapture;
use ExeQue\Monocle\Lenses\Packages\Livewire\LivewireLens;
use ExeQue\Monocle\Lenses\Packages\Livewire\LivewireUpdateCapture;
use ExeQue\Monocle\Lenses\Packages\Mcp\McpLens;
use ExeQue\Monocle\Lenses\Packages\Pennant\FeatureFlagsLens;
use ExeQue\Monocle\Lenses\Packages\Spatie\MediaLibraryLens;
use ExeQue\Monocle\Lenses\Packages\Spatie\ResponseCacheLens;
use ExeQue\Monocle\Lenses\QueueLens;
use ExeQue\Monocle\Lenses\RedisLens;
use ExeQue\Monocle\Lenses\Request\JsonCapture;
use ExeQue\Monocle\Lenses\RequestLens;
use ExeQue\Monocle\Lenses\ScheduleLens;
use ExeQue\Monocle\Lenses\SessionLens;
use ExeQue\Monocle\Lenses\ValidationLens;
use ExeQue\Monocle\Lenses\ViewsLens;
use ExeQue\Monocle\Support\Masks\AuthorizationMask;
use ExeQue\Monocle\Support\Masks\FullMask;
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
    | Throw failures
    |--------------------------------------------------------------------------
    |
    | When a lens fails while recording, the application carries on and the
    | failure is logged and shown on the capture. Turn this on to have the
    | failure thrown instead, for instance while writing a lens and its tests.
    |
    */

    'throw_failures' => env('MONOCLE_THROW_FAILURES', false),

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
        // Laravel
        RequestLens::class => [
            'capture' => [
                'response_types' => [
                    'application/json' => JsonCapture::class,
                    'application/*+json' => JsonCapture::class,
                    'text/html' => InertiaCapture::class,
                ],
                'headers' => [
                    'X-Inertia' => InertiaCapture::class,
                ],
                'routes' => [
                    '*livewire.update' => LivewireUpdateCapture::class,
                ],
            ],
        ],
        SessionLens::class => true,
        AuthLens::class => true,
        ValidationLens::class => true,
        MiddlewareLens::class => true,
        DatabaseLens::class => [
            'slow_ms' => 70,
            'n_plus_one_threshold' => 3,
            'bindings' => true,
            'ignore_tables' => ['telescope_*', 'pulse_*'],
        ],
        EloquentLens::class => true,
        CacheLens::class => [
            'values' => false,
            'slow_ms' => 20,
            'repeat_threshold' => 5,
            'repeat_ms' => 0,
        ],
        RedisLens::class => true,
        ViewsLens::class => [
            'engines' => ['blade', 'php', 'file'],
            'data' => false,
            'repeat_threshold' => 10,
            'repeat_ms' => 20,
        ],
        EventsLens::class => [
            'ignore' => ['Illuminate\*', 'Laravel\*', 'Livewire\*', 'eloquent.*', 'composing:*', 'creating:*', 'bootstrapping:*', 'bootstrapped:*', 'locale.*'],
        ],
        QueueLens::class => true,
        CommandLens::class => true,
        ScheduleLens::class => true,
        HttpClientLens::class => [
            'bodies' => false,
            'capture' => [
                'response_types' => [
                    'application/json' => JsonCapture::class,
                    'application/*+json' => JsonCapture::class,
                ],
            ],
        ],
        MailLens::class => true,
        FilesystemLens::class => [
            'disks' => [],                          // disk names, wildcards allowed: ['media', 'uploads-*'] or ['*']
        ],
        ExceptionsLens::class => true,
        LogLens::class => true,
        AuthorizationLens::class => true,
        DumpLens::class => true,

        // Livewire
        LivewireLens::class => true,

        // laravel/mcp
        McpLens::class => true,

        // laravel/ai
        AiLens::class => [
            'max_text' => null,                     // null = never truncate prompts and responses
        ],

        // Laravel Pennant
        FeatureFlagsLens::class => true,

        // spatie/laravel-responsecache
        ResponseCacheLens::class => true,

        // spatie/laravel-medialibrary
        MediaLibraryLens::class => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Except
    |--------------------------------------------------------------------------
    |
    | Requests whose path matches one of these patterns, and console commands
    | whose name matches, are never recorded, nor are Livewire updates whose
    | components all match `livewire` (dashboards polling themselves).
    | Monocle's own MCP server is listed here; the dashboard is excluded on
    | its own.
    |
    */

    'except' => [
        'paths' => [
            // Laravel
            'up', 'broadcasting/auth', '.well-known/appspecific/*',
            // Livewire
            'livewire-*/livewire*.js', 'livewire-*/*.map', 'livewire-*/js/*', 'livewire-*/css/*',
            // Livewire Flux
            'flux/*',
            // Laravel Horizon
            'horizon', 'horizon/*',
            // Laravel Telescope
            'telescope', 'telescope/*',
            // Laravel Pulse
            'pulse', 'pulse/*',
            // Laravel Boost
            '_boost/browser-logs',
            // Clockwork
            '__clockwork*', 'clockwork', 'clockwork/*',
            // Laravel Debugbar
            '_debugbar*',
            // Ignition
            '_ignition*',
            // spatie/laravel-health (Oh Dear)
            'oh-dear-health-check-results',
        ],
        'commands' => [
            // Monocle
            'monocle:*', 'mcp:start monocle',
            // Laravel
            'queue:work', 'queue:listen', 'schedule:work', 'schedule:finish', 'package:discover', 'test', 'tinker',
            // Laravel Boost
            'boost:*',
            // Laravel Horizon
            'horizon*',
            // Laravel Pulse
            'pulse:check', 'pulse:work',
            // Laravel Reverb
            'reverb:start', 'reverb:restart',
            // Laravel Octane
            'octane:start', 'octane:status',
            // Laravel Nightwatch
            'nightwatch:agent',
            // spatie/laravel-health
            'health:schedule-check-heartbeat', 'health:queue-check-heartbeat',
        ],
        'livewire' => [
            // Laravel Pulse
            'pulse.*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Masking
    |--------------------------------------------------------------------------
    |
    | Headers and cookies are mapped to the mask used for them. Names may use
    | wildcards; header names are lowercase. The session cookie is always
    | masked, even when it is not listed.
    |
    | Input keys matching the `inputs` expression are masked with `using`,
    | as are list entries given without a mask.
    |
    | Entries apply to every lens. Key them by a lens class to apply them to
    | that lens only; a mask set for one lens wins over the shared one:
    |
    |     'headers' => [HttpClientLens::class => ['x-api-key' => FullMask::class]],
    |     'inputs' => ['/pass|token/i', HttpClientLens::class => '/client_secret/i'],
    |
    | A mask is an invokable receiving ($value, $key). Pass constructor
    | arguments with an array, class first:
    |
    |     'authorization' => PartialMask::class,
    |     'authorization' => [PartialMask::class, 8],
    |     'authorization' => [PartialMask::class, 'visible' => 8],
    |
    */

    'mask' => [
        'headers' => [
            'authorization' => [AuthorizationMask::class, [
                'Basic', 'Bearer', 'Digest', 'Token', 'Negotiate', 'NTLM', 'AWS4-HMAC-SHA256', 'Hawk',
            ]],
            'proxy-authorization' => [AuthorizationMask::class, [
                'Basic', 'Bearer', 'Digest', 'Token', 'Negotiate', 'NTLM', 'AWS4-HMAC-SHA256', 'Hawk',
            ]],
            'cookie' => FullMask::class,
            'set-cookie' => FullMask::class,
            'x-xsrf-token' => PartialMask::class,
            'x-csrf-token' => PartialMask::class,
        ],
        'cookies' => [
            'XSRF-TOKEN' => PartialMask::class,
            'remember_*' => PartialMask::class,
        ],
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
    | `tabs` orders the section tabs after Request, Session and Queries: a
    | lens class or a section key per entry. Sections not listed follow.
    |
    */

    'dashboard' => [
        'enabled' => env('MONOCLE_DASHBOARD', true),
        'path' => env('MONOCLE_PATH', '_monocle'),
        'domain' => env('MONOCLE_DOMAIN'),
        'middleware' => ['web'],
        'tabs' => [
            RequestLens::class,
            SessionLens::class,
            DatabaseLens::class,
            MiddlewareLens::class,
            ExceptionsLens::class,
            ValidationLens::class,
            ResponseCacheLens::class,
            FeatureFlagsLens::class,
            CacheLens::class,
            ViewsLens::class,
            HttpClientLens::class,
            AiLens::class,
            FilesystemLens::class,
            MediaLibraryLens::class,
            QueueLens::class,
            LivewireLens::class,
            EloquentLens::class,
            MailLens::class,
            LogLens::class,
            DumpLens::class,
            RedisLens::class,
            EventsLens::class,
            AuthorizationLens::class,
            McpLens::class,
            ScheduleLens::class,
        ],
    ],

];
