<?php

declare(strict_types=1);

use ExeQue\Monocle\Lenses\CacheLens;
use ExeQue\Monocle\Lenses\DatabaseLens;
use ExeQue\Monocle\Lenses\MiddlewareLens;
use ExeQue\Monocle\Lenses\RequestLens;
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
        RequestLens::class => true,
        MiddlewareLens::class => true,
        DatabaseLens::class => [
            'slow_ms' => 70,
            'n_plus_one_threshold' => 3,
            'bindings' => true,
        ],
        CacheLens::class => [
            'values' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Except
    |--------------------------------------------------------------------------
    |
    | Requests whose path matches one of these patterns, and console commands
    | whose name matches, are never recorded. Monocle's own endpoints and MCP
    | server live here so the profiler does not profile itself.
    |
    */

    'except' => [
        'paths' => ['_monocle', '_monocle/*', '__clockwork', '__clockwork/*', '_debugbar/*', 'horizon', 'horizon/*'],
        'commands' => ['monocle:*', 'mcp:start lens', 'boost:*', 'queue:work', 'queue:listen', 'horizon*', 'schedule:work'],
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
    | Entries are kept in a ring buffer holding the newest `limit` entries.
    | The driver may be "file", "cache", a class implementing the Store
    | contract, or a name registered with Monocle::extendStore().
    |
    */

    'storage' => [
        'driver' => env('MONOCLE_STORAGE', 'file'),
        'limit' => 200,
        'file' => [
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

];
