<!-- Part of the Monocle documentation - see README.md -->

# Writing a lens

A lens records something into its own section of the capture. Write one when you want Monocle to show something it does not record on its own - calls to a payment provider, a search index, an internal service.

## A lens

```php
namespace App\Monocle;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;

final class StripeLens implements Lens
{
    public function key(): string
    {
        return 'stripe';
    }

    public function description(): string
    {
        return 'Stripe API calls: endpoint, HTTP status, duration and the Stripe error code for failed calls.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $eyepiece->listen(StripeCallCompleted::class, function (StripeCallCompleted $event) use ($eyepiece): void {
            $eyepiece->push('stripe', [
                'endpoint' => $event->endpoint,
                'status' => $event->status,
                'duration' => $event->milliseconds,
                'error' => $event->errorCode,
            ]);
        });
    }
}
```

| Method | Purpose |
|---|---|
| `key()` | The name of the section, e.g. `monocle-rows section=stripe`. |
| `description()` | Tells the agent what the section contains. Mention the fields that matter. |
| `boot()` | Called once when Monocle starts. Listen to events or hook in however you need to. Its parameters are resolved from the container, like the constructor's - take what you need in either. |

Register it in `config/monocle.php`:

```php
'lenses' => [
    // ...
    App\Monocle\StripeLens::class => true,
],
```

Or from a service provider's `register()` method:

```php
Monocle::register(App\Monocle\StripeLens::class);
```

## Recording

```php
$eyepiece->push('stripe', ['endpoint' => '/charges', 'duration' => 120.4]);   // add a row
$eyepiece->set('stripe_account', ['id' => 'acct_123']);                       // set key/value data
$eyepiece->span('stripe charge', 'stripe')->start();                          // a span in the timeline
$eyepiece->listen(SomeEvent::class, fn (SomeEvent $event) => ...);            // listen to an event
```

`push()` adds the time the row started (rows with a `duration` are dated back by it) and the `origin` (the line in your code that caused it) for you. An exception thrown inside a lens never reaches your application: it is logged and shown as a warning on the capture. While you work on a lens, set `MONOCLE_THROW_FAILURES=true` to have it thrown instead.

## Options

Implement `Configurable` to receive options from the config:

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
App\Monocle\StripeLens::class => ['slow_ms' => 300],
```

## Totals and warnings

Implement `Summarizable` to add to the summary in `monocle-show`:

```php
use ExeQue\Monocle\Contracts\Lenses\Summarizable;

public function summarize(Capture $capture): array
{
    $rows = $capture->rows('stripe');
    $failed = array_filter($rows, fn (array $row): bool => $row['error'] !== null);

    return [
        'totals' => ['stripe_calls' => count($rows)],
        'warnings' => $failed === [] ? [] : [count($failed).' Stripe call(s) failed.'],
    ];
}
```

## Advice for agents

The Monocle skill lists every lens with one sentence on when to look at it. Implement `Advises` to write that sentence yourself; otherwise the first sentence of `description()` is used.

```php
use ExeQue\Monocle\Contracts\Lenses\Advises;

final class PaymentsLens implements Advises, Lens
{
    public function advice(): string
    {
        return 'Look here when a checkout fails or is slow: every call to the payment provider.';
    }
}
```

## Before and after each capture

- `Finalizable::finalize(Capture $capture)` - runs just before a capture is saved.
- `Resettable::reset()` - runs after each capture, to clear anything you kept between events.

## Examples

### Timing a client you call

When the code you want to see has no events, wrap it. Replace the binding with a version that times each call: a span on the timeline and a row in the section.

```php
namespace App\Monocle;

use App\Search\SearchClient;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;

final class SearchLens implements Lens
{
    public function key(): string
    {
        return 'search';
    }

    public function description(): string
    {
        return 'Search queries: index, query, number of hits and duration in ms.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $app->extend(SearchClient::class, fn (SearchClient $client): SearchClient => new TimedSearchClient($client, $eyepiece));
    }
}
```

```php
namespace App\Monocle;

use App\Search\SearchClient;
use ExeQue\Monocle\Eyepiece;

final class TimedSearchClient implements SearchClient
{
    public function __construct(private SearchClient $inner, private Eyepiece $eyepiece)
    {
    }

    public function search(string $index, string $query): array
    {
        $span = $this->eyepiece->span("search {$index}", 'search')->start(['query' => $query]);
        $hits = $this->inner->search($index, $query);
        $span->stop(['hits' => count($hits)]);

        $this->eyepiece->push('search', [
            'index' => $index,
            'query' => $query,
            'hits' => count($hits),
            'duration' => $span->duration(),
        ]);

        return $hits;
    }
}
```

### Facts about the capture

`set()` stores key/value data instead of rows - useful for facts that hold for the whole capture, like the tenant a request ran for.

```php
public function boot(Application $app, Eyepiece $eyepiece): void
{
    $eyepiece->listen(TenancyInitialized::class, function (TenancyInitialized $event) use ($eyepiece): void {
        $eyepiece->set('tenant', [
            'id' => $event->tenant->getTenantKey(),
            'domain' => $event->tenant->domain,
        ]);
    });
}
```

### Collecting rows and warning about them

A lens can keep rows itself and write them when the capture ends. This one lists translation keys that were missing in the request, once per key with how often it was used, and warns about them.

```php
namespace App\Monocle;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;

final class MissingTranslationsLens implements Finalizable, Lens, Resettable, Summarizable
{
    /** @var array<string, array{key: string, locale: string, count: int}> */
    private array $missing = [];

    private Eyepiece $eyepiece;

    public function key(): string
    {
        return 'missing_translations';
    }

    public function description(): string
    {
        return 'Translation keys that had no translation: key, locale and how often it was used.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        $app['translator']->handleMissingKeysUsing(function (string $key, array $replace, ?string $locale) {
            $id = $locale.'|'.$key;
            $this->missing[$id] ??= ['key' => $key, 'locale' => (string) $locale, 'count' => 0];
            $this->missing[$id]['count']++;

            return $key;
        });
    }

    public function finalize(Capture $capture): void
    {
        foreach ($this->missing as $row) {
            $this->eyepiece->push('missing_translations', $row, origin: false);
        }
    }

    public function reset(): void
    {
        $this->missing = [];
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('missing_translations');

        return [
            'totals' => ['missing_translations' => count($rows)],
            'warnings' => $rows === [] ? [] : [count($rows).' translation key(s) have no translation, e.g. '.$rows[0]['key'].'.'],
        ];
    }
}
```

This replaces a missing-key callback the application already set; call that one from inside yours if you have it.

## Testing

```php
config(['monocle.enabled' => true]);
app(Eyepiece::class)->start(CaptureType::Request);

event(new StripeCallCompleted('/charges', 200, 120.4, null));

$capture = app(Eyepiece::class)->finalize();

expect($capture->rows('stripe'))->toHaveCount(1);
```
