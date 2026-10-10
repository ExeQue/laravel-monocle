<!-- Part of the Monocle documentation - see README.md -->

# Configuration

```bash
php artisan vendor:publish --tag=monocle-config
```

Everything Monocle does is driven by `config/monocle.php`. Nothing is hard-coded beyond the defaults in that file.

## `enabled`

`null` (default) means: record outside `production` when `app.debug` is on, never inside the test runner. `true`/`false` force it. `MONOCLE_ENABLED` in `.env` sets it. A runtime `Monocle::enabled(bool|Closure)` from a service provider's `register()` wins over both. See [Installation](installation.md#enabling-monocle).

## `lenses`

A map of lens class to `true` (defaults), `false` (off) or an options array:

```php
'lenses' => [
    RequestLens::class    => true,
    MiddlewareLens::class => ['slow_ms' => 50],
    DatabaseLens::class   => ['slow_ms' => 70, 'n_plus_one_threshold' => 3, 'bindings' => true],
    CacheLens::class      => ['values' => false],
    App\Monocle\StripeLens::class => ['slow_ms' => 300],
],
```

Options reach the lens through `Configurable::configure()`. The same map can be changed at runtime, before Monocle boots:

```php
Monocle::register(App\Monocle\StripeLens::class, ['slow_ms' => 300]);  // add or override options
Monocle::disable(CacheLens::class);                                      // off regardless of config
Monocle::enable(CacheLens::class);                                       // back on with its configured options
Monocle::lenses();                                                       // the effective map
```

The facade is `Conditionable`: `Monocle::when($this->app->isLocal())->register(...)`.

## `except`

Requests and commands that are never recorded. `paths` are matched with `Request::is()`; `commands` with `Str::is()` against the command name. The defaults exclude Monocle's own endpoints and MCP server, Boost, Horizon, Clockwork and Debugbar, and the long-running queue and scheduler workers (the jobs they run are recorded as captures of their own).

## `mask`

```php
'mask' => [
    'headers' => ['authorization', 'cookie', 'set-cookie', 'x-xsrf-token', 'x-csrf-token'],
    'inputs' => '/pass|token|secret|authorization/i',
    'using' => ExeQue\Monocle\Support\Masks\PartialMask::class,
],
```

`headers` are matched case-insensitively; `inputs` is a regex tested against input keys, recursively. `using` is an invokable class (or any callable) receiving `($value, $key)` and returning the masked value. The default `PartialMask` keeps the first characters visible - at most four and never more than a quarter of the value - so an agent can tell *which* token was sent without seeing it: `Bear****`, `hunt****`. `FullMask` replaces everything.

## `origin`

```php
'origin' => ['enabled' => true, 'frames' => 5, 'include_vendor' => []],
```

Every row and span records the user-land call site. `frames` is how many extra frames of the call chain to keep. `include_vendor` lists path fragments inside `vendor/` that should count as your code (your own packages). Turning it off removes the stack capture entirely.

## `storage`

See [Storage](storage.md). `driver` is `file` (default), `cache`, a class implementing `Store`, or a name registered with `Monocle::extendStore()`. `limit` is the ring buffer size in captures.

## `mcp`

```php
'mcp' => ['enabled' => true, 'server' => 'monocle', 'except_servers' => ['monocle', 'laravel-boost']],
```

`enabled` registers the local MCP server; `server` is its handle (`mcp:start <server>`). `except_servers` names MCP servers that must never be recorded by the upcoming `McpLens` - Monocle's own and Boost's by default.
