<!-- Part of the Monocle documentation - see README.md -->

# Writing a lens

A lens is a class that hooks into the framework however it needs to and writes one section of the entry. Monocle's own lenses are written exactly like yours.

## The contract

```php
namespace App\Monocle;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;

final class StripeLens implements Lens
{
    private Eyepiece $eyepiece;

    public function key(): string
    {
        return 'stripe';
    }

    public function description(): string
    {
        return 'Stripe API calls: endpoint, HTTP status and duration. Failed calls carry the Stripe error code.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        $eyepiece->listen(StripeCallCompleted::class, function (StripeCallCompleted $event): void {
            $this->eyepiece->push('stripe', [
                'endpoint' => $event->endpoint,
                'status' => $event->status,
                'duration' => $event->milliseconds,
                'error' => $event->errorCode,
            ]);
        });
    }
}
```

That is the whole required surface:

| Method | Called |
|---|---|
| `key()` | The section name. Also what the agent passes to `monocle-rows section=...`. |
| `description()` | Shown to the agent in the server instructions and in `available_sections`. Say what the rows contain and which fields matter. |
| `boot()` | Once per process, only when Monocle is enabled and the lens is enabled. Hook anything: events, container extensions, `Queue::createPayloadUsing()`, decorators. |

Lifecycle beyond that is opt-in through small companion contracts in `ExeQue\Monocle\Contracts\Lenses`:

| Contract | Method | When |
|---|---|---|
| `Configurable` | `configure(array $options)` | Before `boot()`, with the options array from config |
| `Finalizable` | `finalize(Entry $entry)` | Right before the entry is stored - flush buffers, derive spans |
| `Resettable` | `reset()` | After every entry - forget per-entry state in long-running workers |
| `Summarizable` | `summarize(Entry $entry): array` | When the summary is built - contribute totals and warnings |

Implement only what you use; `MiddlewareLens`, for instance, needs none of them.

## The Eyepiece

`Eyepiece` is the write API every lens uses:

```php
$eyepiece->push('stripe', ['endpoint' => '/charges', 'duration' => 120.4]);   // append a row
$eyepiece->push('stripe', [...], origin: false);                               // without stack capture
$eyepiece->set('stripe_account', ['id' => 'acct_123']);                        // associative section, merged
$eyepiece->span('stripe charge', 'stripe')->start()->stop();                   // spans with your own kind
$eyepiece->now();                                                              // ms since the entry started
$eyepiece->recording();                                                        // false while nothing or a discarded entry is active
$eyepiece->masker()->inputs($payload);                                         // the configured mask
```

`push()` adds `time` and `origin` for you and normalizes values. Calls made while no entry is active are ignored, so you do not need to guard for it, but checking `recording()` first saves the work when the event is frequent.

`listen()` registers an event listener whose failures are caught and logged, and everything else the eyepiece does is wrapped the same way: an exception inside a lens is logged once and never reaches the application. Use `$eyepiece->listen()` rather than the event dispatcher directly.

## Options

Implement `Configurable` to receive the array configured for your lens:

```php
use ExeQue\Monocle\Contracts\Lenses\Configurable;

final class StripeLens implements Configurable, Lens
{
    private int $slowMs = 500;

    public function configure(array $options): void
    {
        $this->slowMs = (int) ($options['slow_ms'] ?? $this->slowMs);
    }
}
```

```php
// config/monocle.php
'lenses' => [
    App\Monocle\StripeLens::class => ['slow_ms' => 300],
],
```

## Contributing to the summary

Implement `Summarizable` to add totals and warnings to `monocle-show`:

```php
use ExeQue\Monocle\Contracts\Lenses\Summarizable;

public function summarize(Entry $entry): array
{
    $rows = $entry->rows('stripe');
    $failed = array_filter($rows, fn (array $row): bool => $row['error'] !== null);

    return [
        'totals' => [
            'stripe_calls' => count($rows),
            'stripe_ms' => round(array_sum(array_column($rows, 'duration')), 3),
        ],
        'warnings' => $failed === [] ? [] : [count($failed) . ' Stripe call(s) failed.'],
    ];
}
```

Rows whose section is listed in the summary's category map (`queries`, `cache`, `redis`, `http`, `fs`, `mail`) also count towards the time breakdown when they carry a `duration`.

## Registering

Either in config or from a service provider's `register()`:

```php
Monocle::register(App\Monocle\StripeLens::class, ['slow_ms' => 300]);
```

Lenses are resolved through the container, so constructor injection works. Registering after Monocle has booted throws `LensAlreadyBootedException`; `register()` of a provider is early enough, `boot()` is not guaranteed to be.

## Hooking without events

Not everything fires events. Monocle's `MiddlewareLens` extends middleware classes in the container with a timing decorator; `QueueLens` adds a bus pipe and a payload hook; the planned `FilesystemLens` rebinds the filesystem manager to wrap Flysystem adapters. A lens may do the same - `boot()` receives the application, and it runs before the request is handled.

## Testing a lens

Monocle's own tests use [Orchestra Testbench](https://packages.tools/testbench). In an application, enable recording in the test and start an entry by hand:

```php
config(['monocle.enabled' => true]);
app(Eyepiece::class)->start(EntryType::Request);

event(new StripeCallCompleted('/charges', 200, 120.4, null));

$entry = app(Eyepiece::class)->finalize();

expect($entry->rows('stripe'))->toHaveCount(1);
```
