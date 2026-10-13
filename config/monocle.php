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
use ExeQue\Monocle\Lenses\Packages\Filament\FilamentLens;
use ExeQue\Monocle\Lenses\Packages\Filament\FilamentLivewireLens;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLCapture;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLLens;
use ExeQue\Monocle\Lenses\Packages\GraphQL\Lighthouse\LighthouseHook;
use ExeQue\Monocle\Lenses\Packages\GraphQL\Rebing\RebingHook;
use ExeQue\Monocle\Lenses\Packages\Inertia\InertiaCapture;
use ExeQue\Monocle\Lenses\Packages\Inertia\InertiaLens;
use ExeQue\Monocle\Lenses\Packages\Lab404\ImpersonateLens;
use ExeQue\Monocle\Lenses\Packages\Laravel\AiLens;
use ExeQue\Monocle\Lenses\Packages\Laravel\FortifyLens;
use ExeQue\Monocle\Lenses\Packages\Laravel\McpLens;
use ExeQue\Monocle\Lenses\Packages\Laravel\NovaLens;
use ExeQue\Monocle\Lenses\Packages\Laravel\PennantLens;
use ExeQue\Monocle\Lenses\Packages\Laravel\SanctumLens;
use ExeQue\Monocle\Lenses\Packages\Livewire\LivewireLens;
use ExeQue\Monocle\Lenses\Packages\Livewire\LivewireUpdateCapture;
use ExeQue\Monocle\Lenses\Packages\Mirror\MirrorLens;
use ExeQue\Monocle\Lenses\Packages\Prism\PrismLens;
use ExeQue\Monocle\Lenses\Packages\Spatie\EventSourcing\EventSourcingLens;
use ExeQue\Monocle\Lenses\Packages\Spatie\MediaLibraryLens;
use ExeQue\Monocle\Lenses\Packages\Spatie\ResponseCacheLens;
use ExeQue\Monocle\Lenses\Packages\STS\FilamentImpersonateLens;
use ExeQue\Monocle\Lenses\ProcessLens;
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
use Illuminate\Contracts\Auth\Authenticatable;

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
                'max_bytes' => 1024 * 1024,         // larger responses are not formatted or kept raw; null = no limit
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
                    'graphql' => GraphQLCapture::class,
                    'graphql.*' => GraphQLCapture::class,
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
            'bindings' => true,                     // false keeps every value out; sensitive columns are masked by mask.inputs
            'ignore_tables' => ['telescope_*', 'pulse_*'],  // queries on these tables are not recorded
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
        ProcessLens::class => [
            'slow_ms' => 1000,
        ],
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

        // filament/filament
        FilamentLens::class => [
            'large_table_records' => 500,           // warn when a table without pagination loads more records than this
        ],
        FilamentLivewireLens::class => true,

        // inertiajs/inertia-laravel
        InertiaLens::class => [
            'large_prop_bytes' => 50000,            // warn when one prop is bigger than this
            'large_page_bytes' => 200000,           // warn when the whole page is bigger than this
            'max_value_bytes' => 20000,             // keep the values of props up to this size
        ],

        // laravel/mcp
        McpLens::class => true,

        // laravel/ai
        AiLens::class => [
            'max_text' => null,                     // null = never truncate prompts and responses
        ],

        // prism-php/prism
        PrismLens::class => [
            'max_text' => null,                     // null = never truncate prompts and responses
        ],

        // Laravel Pennant
        PennantLens::class => true,

        // spatie/laravel-responsecache
        ResponseCacheLens::class => true,

        // spatie/laravel-medialibrary
        MediaLibraryLens::class => true,

        // spatie/laravel-event-sourcing
        EventSourcingLens::class => [
            'snapshot_threshold' => 100,            // warn when an aggregate is rebuilt from at least this many events without a snapshot
        ],

        /** @see https://exeque.github.io/laravel-monocle/docs/lenses/graphql-hooks */
        GraphQLLens::class => [
            'hooks' => [
                // nuwave/lighthouse
                LighthouseHook::class,
                // rebing/graphql-laravel
                RebingHook::class,
            ],
        ],

        // Additions to the auth lens: laravel/fortify, laravel/sanctum and the
        // impersonation packages lab404/laravel-impersonate,
        // stechstudio/filament-impersonate, laravel/nova and franbarbalopez/mirror
        FortifyLens::class => true,
        SanctumLens::class => true,
        ImpersonateLens::class => true,
        FilamentImpersonateLens::class => true,
        NovaLens::class => true,
        MirrorLens::class => true,
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
            'queue:work', 'queue:listen', 'schedule:work', 'package:discover', 'test', 'tinker',
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
    | Trace
    |--------------------------------------------------------------------------
    |
    | Queued jobs always join the trace of the capture that dispatched them.
    | Turn `browser.enabled` on to link a page to the calls it makes: HTML
    | pages get a small inline script that sends the page's capture id along
    | with its same-origin fetch and XHR calls, and requests carrying it
    | become children of the page. `browser.button` adds a Monocle button to
    | those pages that opens their captures in the dashboard; it needs
    | `browser.enabled` and the dashboard. With `processes` on, commands
    | started through the Process facade join the trace of the capture that
    | started them.
    |
    */

    'trace' => [
        'browser' => [
            'enabled' => false,
            'button' => true,
        ],
        'processes' => true,
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
    | `inputs` also masks query bindings, by the column each value is
    | compared with or written to (`email = ?`, `coalesce(email, '') = ?`,
    | `between`, insert and update columns). Key an expression, or a list
    | of them, by DatabaseLens to mask more columns in queries only; it
    | adds to the shared expressions:
    |
    |     'inputs' => ['/pass|token/i', DatabaseLens::class => '/^(email|iban)$/i'],
    |
    | Log context keys matching the `log` expression are masked with `using`;
    | it is a list of its own, so log context can be masked apart from input.
    |
    | Objects in dumps have their listed properties masked, by class or
    | interface; on Eloquent models the keys are attributes:
    |
    |     'objects' => [Authenticatable::class => ['password'], Card::class => ['number' => FullMask::class]],
    |
    | Entries apply to every lens. Key them by a lens class to apply them to
    | that lens only; a mask set for one lens wins over the shared one, and
    | expressions for `inputs` and `log` add to the shared ones:
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
        'log' => '/pass|token|secret|authorization/i',
        'objects' => [
            Authenticatable::class => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'],
        ],
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
    | `show_host` adds the host of each request to the capture list, for
    | applications that answer on more than one domain.
    |
    | `tabs` orders the section tabs after Overview and Timeline: a lens
    | class (its own section, then the other sections it provides) or a
    | section key per entry. Sections not listed follow.
    |
    */

    'dashboard' => [
        'enabled' => env('MONOCLE_DASHBOARD', true),
        'path' => env('MONOCLE_PATH', '_monocle'),
        'domain' => env('MONOCLE_DOMAIN'),
        'middleware' => ['web'],
        'show_host' => env('MONOCLE_DASHBOARD_SHOW_HOST', false),
        'tabs' => [
            RequestLens::class,
            SessionLens::class,
            DatabaseLens::class,
            MiddlewareLens::class,
            ExceptionsLens::class,
            ValidationLens::class,
            GraphQLLens::class,
            ResponseCacheLens::class,
            PennantLens::class,
            CacheLens::class,
            ViewsLens::class,
            HttpClientLens::class,
            AiLens::class,
            PrismLens::class,
            FilesystemLens::class,
            MediaLibraryLens::class,
            EventSourcingLens::class,
            QueueLens::class,
            FilamentLens::class,
            LivewireLens::class,
            InertiaLens::class,
            EloquentLens::class,
            MailLens::class,
            LogLens::class,
            DumpLens::class,
            RedisLens::class,
            EventsLens::class,
            AuthorizationLens::class,
            McpLens::class,
            ScheduleLens::class,
            ProcessLens::class,
            CommandLens::class,
        ],
    ],

];
