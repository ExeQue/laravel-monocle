<!-- Part of the Monocle documentation - see README.md -->

# Configuration

`monocle:install` writes `config/monocle.php` for you. To get the default file instead:

```bash
php artisan vendor:publish --tag=monocle-config
```

## `enabled`

`null` (default) records outside production when `APP_DEBUG` is on. Set `MONOCLE_ENABLED=true` or `false` in `.env` to force it, or decide at runtime - see [Installation](installation.md#enabling-monocle).

## Lenses

Each lens is listed with `true` (on), `false` (off) or an array of options:

```php
'lenses' => [
    RequestLens::class  => true,
    DatabaseLens::class => ['slow_ms' => 70, 'n_plus_one_threshold' => 3, 'bindings' => true],
    CacheLens::class    => false,
    App\Monocle\StripeLens::class => ['slow_ms' => 300],
],
```

The options of each lens are described in [What gets recorded](lenses.md).

You can also change lenses from a service provider's `register()` method:

```php
Monocle::register(App\Monocle\StripeLens::class, ['slow_ms' => 300]);
Monocle::disable(CacheLens::class);
Monocle::enable(CacheLens::class);
```

## `throw_failures`

When a lens fails while recording, the application carries on: the failure is logged and shown as a warning on the capture. Set `MONOCLE_THROW_FAILURES=true` to have it thrown instead, for instance while writing a lens and its tests.

## `except`

Requests and commands that are never recorded:

```php
'except' => [
    'paths' => ['telescope/*', 'pulse/*', 'horizon/*', 'up', 'livewire-*/livewire*.js', /* ... */],
    'commands' => ['monocle:*', 'queue:work', 'horizon*', 'pulse:work', 'reverb:start', /* ... */],
],
```

Paths and command names support `*`. By default the dashboards and assets of Telescope, Pulse, Horizon, Debugbar, Clockwork, Ignition and Flux are left out, as are Livewire's script and style files, the `up` health route, broadcast channel authorization and long-running commands such as queue workers, Reverb, Octane and Pulse. Livewire updates are still recorded unless every component in them matches `except.livewire` (default `pulse.*`, so the Pulse dashboard polling itself is left out), and so are jobs run by `queue:work` and Horizon. The dashboard itself is never recorded.

## Masking

```php
use ExeQue\Monocle\Support\Masks\FullMask;
use ExeQue\Monocle\Support\Masks\PartialMask;

'mask' => [
    'headers' => [
        'authorization' => PartialMask::class,
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
```

Headers and cookies are listed by name, each with the mask to use. Names support `*`, so `'x-api-*' => FullMask::class` covers every header starting with `x-api-`. Header names are lowercase. The session cookie is always masked, also when it is not listed. A name without a class (`'headers' => ['authorization', 'x-api-key']`) uses `using`.

Input, session data and command options whose key matches `inputs` are masked with `using`, and so are cookies with a matching name.

`PartialMask` keeps the first few characters visible, so you can tell which token was sent without seeing it: `Bear****`. `FullMask` hides the whole value. A mask can also be your own class with an `__invoke($value, $key)` method. Pass constructor arguments by giving an array with the class first: `[PartialMask::class, 8]` or `[PartialMask::class, 'visible' => 8]`.

## `origin`

```php
'origin' => ['enabled' => true, 'frames' => 5, 'include_vendor' => []],
```

Every row records the line in your code that caused it, plus up to `frames` more lines of the call chain. Code in `vendor/` is skipped; list your own packages in `include_vendor` to include them.

## `storage`

```php
'storage' => [
    'driver' => env('MONOCLE_STORAGE', 'file'),
    'limit' => 200,
],
```

How many captures to keep and where. See [Storage](storage.md).

## `mcp`

```php
'mcp' => ['enabled' => true, 'server' => 'monocle', 'except_servers' => ['monocle', 'laravel-boost']],
```

`server` is the name used in `mcp:start monocle`. MCP servers listed in `except_servers` are not recorded.

## `dashboard`

Where the web dashboard lives and whether it is enabled - see [Dashboard](dashboard.md#configuration).
