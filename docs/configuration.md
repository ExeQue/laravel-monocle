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

## `except`

Requests and commands that are never recorded:

```php
'except' => [
    'paths' => ['_monocle', '_monocle/*', 'horizon', 'horizon/*'],
    'commands' => ['monocle:*', 'queue:work', 'horizon*'],
],
```

Paths and command names support `*`. Jobs run by `queue:work` and Horizon are still recorded.

## Masking

```php
'mask' => [
    'headers' => ['authorization', 'cookie', 'set-cookie', 'x-xsrf-token', 'x-csrf-token'],
    'inputs' => '/pass|token|secret|authorization/i',
    'using' => ExeQue\Monocle\Support\Masks\PartialMask::class,
],
```

The listed headers and any input whose name matches `inputs` are masked before they are stored. By default the first few characters stay visible, so you can tell which token was sent without seeing it: `Bear****`. Use `FullMask` to hide the whole value, or point `using` at your own class with an `__invoke($value, $key)` method.

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
