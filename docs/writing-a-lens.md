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
| `boot()` | Optional. Called once when Monocle starts, if the lens has it. Listen to events or hook in however you need to. Its parameters are resolved from the container, like the constructor's - take what you need in either. |

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

`Monocle::register()`, `enable()` and `disable()` must run in `register()`: once the lenses have booted they throw `LensAlreadyBootedException`.

## Recording

```php
$eyepiece->push('stripe', ['endpoint' => '/charges', 'duration' => 120.4]);   // add a row
$eyepiece->set('stripe_account', ['id' => 'acct_123']);                       // set key/value data
$eyepiece->span('stripe charge', 'stripe')->start();                          // a span in the timeline
$eyepiece->listen(SomeEvent::class, fn (SomeEvent $event) => ...);            // listen to an event
```

`push()` adds the time the row started (rows with a `duration` are dated back by it) and the `origin` (the line in your code that caused it) for you.

What the `Eyepiece` offers a lens:

| Method | What it does |
|---|---|
| `push(string $section, array $row, bool $origin = true)` | Adds a row to a section. Pass `origin: false` to leave the `origin` out; a row that already has a `time` or `origin` keeps its own. |
| `set(string $section, array $data)` | Merges key/value data into a section. |
| `span(?string $name = null, string $kind = 'user', ?string $key = null)` | A span for the timeline; call `start()` and `stop()` on it. The kind is the second argument here, while `Monocle::span($name, $key)` takes the key second. |
| `listen(string $event, Closure $handler)` | Listens to an event. |
| `recording()` | Whether a capture is being recorded right now. |
| `now()` | Milliseconds since the capture started. |
| `relative(float $microtime)` | A `microtime(true)` value as milliseconds since the capture started. |
| `currentSpan()` | The innermost running span, or `null`. |
| `meta(array $meta)` | Adds metadata to the capture. |
| `origin()` | The line in your code that is running now, to keep and put on a row you push later. |
| `masker(?string $lens = null)` | Masks values the way the [masking configuration](configuration.md#masking) says, including entries keyed by your lens class: `headers()`, `cookies()`, `inputs()` and `body($body, $contentType)`. |
| `config(string $key, mixed $default = null)` | Reads a value from `config/monocle.php`. |

Methods that record or change something - `push()`, `set()`, `meta()`, `listen()`, `modify()` and the like - return the eyepiece, so the calls chain.

An exception thrown inside a `listen()` handler, or while pushing, setting or timing a span, never reaches your application: it is logged and shown as a warning on the capture. Exceptions thrown in `boot()` itself, or in code you put in place with `$app->extend()` (see [Timing a client you call](#timing-a-client-you-call)), are not caught. While you work on a lens, set `MONOCLE_THROW_FAILURES=true` to have the caught ones thrown instead.

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

`configure()` is only called when the lens is listed with an array of options. With `=> true` it is not called, so give every option its default on the property.

## Totals and warnings

Implement `Summarizable` to add to the summary in `monocle-show` and on the dashboard's overview:

```php
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;

public function summarize(Capture $capture): Summary
{
    $rows = $capture->rows('stripe');
    $failed = array_filter($rows, fn (array $row): bool => $row['error'] !== null);

    return Summary::make()
        ->total('stripe_calls', count($rows), 'calls')
        ->total('stripe_ms', array_sum(array_column($rows, 'duration')), 'spent', Unit::Milliseconds)
        ->total('stripe_failed', count($failed), 'failed')
        ->when($failed !== [])
        ->error(count($failed).' Stripe call(s) failed.');
}
```

`summarize()` runs every time the summary is read - on the dashboard, through MCP and from artisan - possibly long after the capture was recorded. Work from `$capture` alone, not from anything the lens kept while recording.

A summary is immutable: start with `Summary::make()`, and since every method returns a new summary, chain the calls or assign the result. `when()` makes the next call conditional, as above.

| Method | What it adds |
|---|---|
| `total($name, $value, $label = null, $unit = null)` | One total. A `null` value throws `InvalidSummaryTotalException`. |
| `totals([$name => $value, ...], $unit = null)` | Several totals in the same unit, without labels. `null` values are kept. |
| `headline($name, $value, $label = null, $unit = null)` | A total about the whole capture, shown at the top of the overview next to the duration and peak memory instead of under your tab. |
| `warning($message)`, `warnings([...])` | Things worth a look. |
| `error($message)`, `errors([...])` | Things that failed, such as a call or a job. |

The label says what the value is ("failed", "spent"). Without one the overview shows the name, humanised and without its unit suffix (`stripe_ms` becomes "stripe"). The unit decides how the value is shown:

| Unit | Shown as |
| --- | --- |
| `Unit::Milliseconds` | `8.7 ms` |
| `Unit::Bytes` | `26 B`, `11 KB`, `1.2 MB` |
| `Unit::Megabytes` | `4 MB` |
| `Unit::Percent` | `66.7 %` |
| any string, e.g. `'DKK'` | the value followed by the string: `49` shows as `49 DKK` |

A string unit does not format the number; pass `'49.00'` to show `49.00 DKK`.

On the overview your totals are grouped under the name of your tab, in tab order; clicking the group's name opens the tab. Totals that are `null`, `0` or an array are left out of the overview; arrays are still in the summary through MCP and artisan. A total whose name ends in `failed`, `errors`, `exceptions`, `denied`, `declined` or `warnings` shows in red when it is above 0.

Errors are listed before warnings, mark the capture in the dashboard's list and count for its "only errors" filter and for `has_errors` in `monocle-list`. On your tab's count, errors show red and warnings yellow; see [Warnings and errors](dashboard.md#warnings-and-errors).

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

## Lenses for other packages

A lens that records another package implements `RequiresPackages` and names the Composer packages it needs. Unless all of them are installed, the lens is left out entirely - it does not boot, gets no tab and adds nothing to the summary - so it can be listed in the configuration of applications that do not use the package.

```php
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;

final class StripeLens implements Lens, RequiresPackages
{
    public static function packages(): array
    {
        return ['stripe/stripe-php'];
    }
}
```

## Adding to another lens

A lens can add to another one instead of having a section of its own - the way the impersonation packages add to the auth section. Implement `ExtendsLens` and name the lens you add to. Your lens then runs only while that lens is enabled, its totals, warnings and errors count on that lens's tab, and its description is added to that lens's description for agents.

Push rows into that lens's section, and use `modify()` to add to the rows it writes itself:

```php
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Lenses\AuthLens;

final class TeamsLens implements ExtendsLens, Lens, RequiresPackages
{
    public static function extends(): string
    {
        return AuthLens::class;
    }

    public static function packages(): array
    {
        return ['acme/teams'];
    }

    public function boot(): void
    {
        $this->eyepiece->listen(TeamSwitched::class, fn (TeamSwitched $event) => $this->eyepiece->push('auth', [
            'event' => 'team_switched',
            'team' => $event->team->name,
        ]));

        // Every row the auth lens writes for the authenticated user gets the current team.
        $this->eyepiece->modify('auth', fn (array $row): array => ($row['event'] ?? null) === 'authenticated'
            ? [...$row, 'team' => session('team')]
            : $row);
    }
}
```

## Extending the request lens

The request lens keeps a response's body when it has a capture for it, and shows it on the Request tab as cards next to the raw body. Write your own capture for responses that read better another way - an API error format, a GraphQL result, an export.

```php
namespace App\Monocle;

use ExeQue\Monocle\Contracts\ResponseCapture;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProblemCapture implements ResponseCapture
{
    public function capture(Request $request, Response $response): ?array
    {
        $problem = json_decode((string) $response->getContent(), true);

        if (! is_array($problem) || ! isset($problem['title'])) {
            return null;
        }

        return [[
            'title' => $problem['title'],
            'subtitle' => $problem['type'] ?? null,
            'items' => [
                'status' => $problem['status'] ?? $response->getStatusCode(),
                'detail' => $problem['detail'] ?? null,
                'errors' => $problem['errors'] ?? [],
            ],
        ]];
    }
}
```

Each card has a `title`, an optional `subtitle` and named `items`. Items can be text, numbers, lists and maps. A list of `field`/`from`/`to` entries shows as a table of changes, and a list of other objects as a table. Return `null` when the response is not one the capture understands; nothing is kept then.

Captures are resolved from the container, so the constructor can take what it needs.

Register the capture in `config/monocle.php` for a route name, a response header or a content type (wildcards allowed in route names and content types):

```php
RequestLens::class => [
    'capture' => [
        'routes' => [
            'api.orders.*' => OrderCapture::class,
        ],
        'headers' => [
            'X-Export' => ExportCapture::class,
        ],
        'response_types' => [
            'application/problem+json' => ProblemCapture::class,
            'application/json' => JsonCapture::class,
        ],
    ],
],
```

A route capture wins over a header capture, which wins over a content type capture. Only responses with a capture are kept; other responses only have their size.

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

`monocle-show sections=["tenant"]` returns it. On the dashboard such a section only gets a tab when the lens has a tab view; see [Your own tab](#your-own-tab).

### Collecting rows and warning about them

A lens can keep rows itself and write them when the capture ends. This one lists translation keys that were missing in the request, once per key with how often it was used, and warns about them.

```php
namespace App\Monocle;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
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

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('missing_translations');
        return Summary::make()
            ->total('missing_translations', count($rows), 'missing')
            ->when($rows !== [])
            ->warning(count($rows).' translation key(s) have no translation, e.g. '.($rows[0]['key'] ?? '').'.');
    }
}
```

This replaces a missing-key callback the application already set; call that one from inside yours if you have it.

## Your own tab

A section gets a tab in the dashboard when it has rows (from `push()`), or when it has data (from `set()`) and its lens has a tab view of its own (see `tab()` below). Data from `set()` without a tab view gets no tab; it is still available through MCP and artisan.

Without anything else the tab is a table worked out from the rows. To decide how yours looks, implement `Presentable` and describe it:

```php
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Dashboard\Presentation;

final class PaymentsLens implements Lens, Presentable
{
    public function presentation(): Presentation
    {
        return Presentation::table()
            ->label('Payments')
            ->columns(['customer', 'amount' => 'Amount', 'currency', 'status'])
            ->detailsOnly(['card', 'decline_reason'])
            ->groupBy(['customer' => 'by customer', 'status' => 'by outcome'])
            ->format(['status' => 'status', 'refunded' => ['flag', 'refunded', null, 'bad', 'warn']])
            ->highlight(['declined' => 'bad'])
            ->details('monocle.payment');
    }
}
```

`Presentation::table()` (or `Presentation::make()`) starts an empty presentation. Every method returns a new one, so chain the calls or assign the result.

| Method | What it does |
|---|---|
| `label()` | the tab's name |
| `columns()` | the table's columns, in order: a list of fields, or field => header |
| `detailsOnly()` | fields only shown when a row is expanded |
| `groupBy()` | fields the rows can be grouped by, with the label of their chip |
| `details()` | a Blade view rendering an expanded row, instead of the list of its fields |
| `tab()` | a Blade view for the whole tab, instead of the table |
| `includes()` | other sections to show on this tab |
| `format()` | how fields show in the table; see below |
| `highlight()` | tints rows where a field is set: field => `'warn'` or `'bad'` |

The presentation, the tab view, the tab's place and its count belong to the section named by your lens's `key()`. Rows you push to a section with another name get a plain table of their own.

Tabs are ordered by `dashboard.tabs` in the configuration, which lists lens classes or section keys. Tabs not listed there, such as your own, follow the listed ones in the order their sections were recorded. Overview and Timeline always come first and Trace last. See [Dashboard](dashboard.md#configuration).

### Formatting fields

Durations (`duration`, `*_ms`), sizes (`*_bytes`), file paths, exceptions, `{field, from, to}` changes and long text are formatted wherever they appear. `format()` picks how other fields show, by name:

| Format | Shows |
|---|---|
| `'http-status'` | an HTTP status code, coloured by class; empty means the call failed |
| `'status'` | a status word, coloured: `processed` green, `failed` red, and so on |
| `'level'` | a log level, coloured by severity |
| `['flag', 'yes', 'no']` | `true` or `false` as a word: true in green, false in red. Add two tones (`'ok'`, `'warn'`, `'bad'`, `'muted'`) to pick the colours of false and true, and pass `null` to show nothing for one of them |
| `'bool'` | `true` in green, `false` muted |
| `'code'` | the value in full in a monospace font, such as a command line |
| `['prefix', '#']` | the value after a prefix |
| `['reference', 'repeat of #']` | a badge pointing at another row |
| `['highlight', ['miss', 'failed'], 'warn']` | the listed values in a colour |
| `'fields'` | a key/value map as a short list |
| `'rules'` | validation rules per field |

When rows are grouped, a group counts a row as failed when its `failed` field is `true`, a `flag` field shown in red is `false` or an `http-status` field is empty or 400 and up. A row inside a group leads with its first `http-status`, `status` or `level` field.

A column that is empty, `false` or `0` in every row, or `true` in every row, is left out.

### Other sections on your tab

`includes(['stripe_webhooks'])` shows other sections on your tab; give one a presentation of its own to format its table, as in `includes(['stripe_webhooks' => Presentation::table()->label('Webhooks')->format([...])])`. Their rows are loaded with the tab into `tabData.included.stripe_webhooks`, and the default table (also `<x-monocle::section-table />` in a tab view) shows them as extra tables under yours. While your tab is shown they get no tab of their own, and their warnings and errors count on your tab.

### An expanded row

The details view receives `$row`, the row as your lens recorded it, and `$capture`. It is rendered inside the dashboard, so the dashboard's colours apply: `text-muted`, `text-ok`, `text-bad`, `text-warn` and `font-mono` read like the rest of it.

```blade
<div class="grid gap-2">
    <p class="font-semibold">{{ number_format($row['amount'] / 100, 2) }} {{ $row['currency'] }}</p>
    <p class="text-muted">Charged to {{ $row['customer'] }}</p>
    <x-monocle::facts :items="[['Status', $row['status'], $row['status'] === 'failed' ? 'text-bad' : ''], ['Card', $row['card']]]" />
    <x-monocle::origin :origin="$row['origin'] ?? null" />
</div>
```

These components take PHP values and fit a details view:

| Component | Props | Shows |
|---|---|---|
| `<x-monocle::block>` | `title`, `copy` | a labelled block, with a copy button for `copy` when given |
| `<x-monocle::facts>` | `items` | short facts side by side, each `[label, value]` or `[label, value, class]`; empty values are left out |
| `<x-monocle::pairs>` | `pairs`, `what` | `[key, value]` pairs as a table with a copy button per value |
| `<x-monocle::name>` | `value`, `class`, `full` | a class name or path, shortened unless `full`, linked to your editor |
| `<x-monocle::exception>` | `exception` | a recorded exception and the ones it wraps |
| `<x-monocle::origin>` | `origin` | where the row came from and the call chain |

### A tab view

A tab view is part of the dashboard page and receives `$section`, the section key. Wrap it in a panel, and put the content in a template that only exists while your tab is open, so `tabData` belongs to your tab:

```blade
<x-monocle::panel name="payments">
    <template x-if="tab === 'payments'">
        <div class="grid gap-4">
            <x-monocle::section title="Charges">
                <x-monocle::section-table />
            </x-monocle::section>
            <p :class="cls.empty" x-show="! tabLoading && ! tabData?.rows?.length">No charges.</p>
        </div>
    </template>
</x-monocle::panel>
```

The dashboard's Alpine state a tab view can read:

| Name | Holds |
|---|---|
| `tab` | the key of the open tab |
| `capture` | the open capture |
| `summary` | its summary |
| `tabData` | the open tab's data: `rows`, `data` for a section filled with `set()`, `included` and `next_offset` |
| `tabLoading` | `true` while the tab's data loads |
| `cls` | the dashboard's class sets, such as `cls.sectionTitle`, `cls.tableWrap`, `cls.th`, `cls.td` and `cls.empty` |

These components take Alpine expressions and fit a tab view:

| Component | Props | Shows |
|---|---|---|
| `<x-monocle::panel>` | `name`, `show` | the tab's panel, shown while the tab named `name` is open or while `show` is true |
| `<x-monocle::section>` | `title`, `show` | a titled block, shown while `show` is true when given |
| `<x-monocle::section-table>` | | the usual table of the open tab, with its grouping chips and **Show more** |
| `<x-monocle::kv-table>` | `rows`, `keys`, `value` | a key/value table, one row per `[key, value]` pair in `rows`, e.g. `rows="Object.entries(tabData?.data ?? {})"` |
| `<x-monocle::badge>` | `count` | a rounded count |
| `<x-monocle::chips>` | `options`, `active`, `pick`, `label`, `lead` | a row of toggle chips |
| `<x-monocle::fold-heading>` | `open` | a section title that toggles the Alpine property named by `open` |
| `<x-monocle::collapse>` | `open`, `html` | content that folds open while `open` is true |
| `<x-monocle::expand-button>` | `open`, `toggle`, `label`, `chevron` | the button that folds a row out |
| `<x-monocle::chevron>` | `open`, `size` | a chevron that turns while `open` is true |
| `<x-monocle::copy>` | `text`, or `bind`, `what`, `what-bind`, `hover` | a copy button for `text`, or for the Alpine expression in `bind` |

## Testing

Monocle does not record while tests run unless it is enabled, and lenses boot together with the application, so setting `monocle.enabled` inside a test is too late. Enable it before the application boots. In an application, add it to `phpunit.xml`:

```xml
<php>
    <env name="MONOCLE_ENABLED" value="true"/>
    <env name="MONOCLE_THROW_FAILURES" value="true"/>
</php>
```

In a package tested with Orchestra Testbench, set it in `defineEnvironment()`, together with your lens:

```php
use App\Monocle\StripeLens;

protected function defineEnvironment($app): void
{
    $app['config']->set('monocle.enabled', true);
    $app['config']->set('monocle.throw_failures', true);
    $app['config']->set('monocle.lenses.'.StripeLens::class, true);
}
```

Then start a capture, do the work and finalize it:

```php
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;

app(Eyepiece::class)->start(CaptureType::Request);

event(new StripeCallCompleted('/charges', 200, 120.4, null));

$capture = app(Eyepiece::class)->finalize();

expect($capture->rows('stripe'))->toHaveCount(1);
```

`MONOCLE_THROW_FAILURES` makes an exception in your lens fail the test instead of being logged.

## Sharing your lens

Publish the lens as its own package and open an issue to have it listed under [Third-party lenses](third-party-lenses.md).
