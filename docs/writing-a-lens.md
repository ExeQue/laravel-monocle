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
| `boot()` | Called once when Monocle starts. Listen to events or hook in however you need to. |

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

`push()` adds the time the row started (rows with a `duration` are dated back by it) and the `origin` (the line in your code that caused it) for you. An exception thrown inside a lens is logged and never reaches your application.

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

## Before and after each capture

- `Finalizable::finalize(Capture $capture)` - runs just before a capture is saved.
- `Resettable::reset()` - runs after each capture, to clear anything you kept between events.

## Testing

```php
config(['monocle.enabled' => true]);
app(Eyepiece::class)->start(CaptureType::Request);

event(new StripeCallCompleted('/charges', 200, 120.4, null));

$capture = app(Eyepiece::class)->finalize();

expect($capture->rows('stripe'))->toHaveCount(1);
```
