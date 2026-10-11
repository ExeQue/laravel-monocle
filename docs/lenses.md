<!-- Part of the Monocle documentation - see README.md -->

# What gets recorded

A **capture** is one request, queued job, artisan command or MCP call. Each capture has a timeline of timed **spans** and a number of **sections** with rows. Each section is filled by a **lens**.

Every row records when it happened and the `origin` - the file and line in your code that caused it.

Turn lenses on, off or change their options in [`config/monocle.php`](configuration.md#lenses).

Warnings point at your code. Slow or repeated work that comes from a package on its own - a slow query a library runs, a package's middleware - is not flagged; possible N+1 queries are flagged wherever they come from.

## At a glance

### Laravel

| Lens | Section | Records |
|---|---|---|
| [Request](#request) | `request` | method, URL, input, headers, cookies, route, response and phases |
| [Session](#session) | `session` | session contents and what the request changed |
| [Auth](#auth) | `auth` | the user, logins, failed attempts and lockouts |
| [Validation](#validation) | `validation` | every validation, its rules and the rules that failed |
| [Middleware](#middleware) | timeline | each middleware before and after the controller |
| [Database](#database) | `queries` | every query, transactions, N+1 and slow queries |
| [Eloquent](#eloquent) | `models`, `model_changes` | models loaded and every changed attribute |
| [Cache](#cache) | `cache` | hits, misses, writes and repeated reads |
| [Redis](#redis) | `redis` | every Redis command |
| [Views](#views) | `views` | every view and component with its duration |
| [Events](#events) | `events` | your events and their listeners |
| [Queue](#queue) | `jobs` | jobs dispatched, and each job as its own capture |
| [Artisan commands](#artisan-commands) | `command` | arguments, options and exit code |
| [Scheduler](#scheduler) | `scheduled_tasks` | each scheduled task and its outcome |
| [HTTP client](#http-client) | `http` | outgoing calls made with the `Http` client |
| [Mail and notifications](#mail-and-notifications) | `mail` | mail and notifications sent |
| [Filesystem](#filesystem) | `filesystem` | operations on the `Storage` disks you choose |
| [Exceptions](#exceptions) | `exceptions` | reported exceptions and where they came from |
| [Log](#log) | `log` | everything written to the log |
| [Authorization](#authorization) | `authorization` | every gate and policy check |
| [Dumps](#dumps) | `dumps` | every `dump()` and `dd()` |

### Packages

These record something only when the package is installed; otherwise they do nothing.

| Package | Lens | Section | Records |
|---|---|---|---|
| [Inertia](https://inertiajs.com) | [Inertia](#inertia) | `request` | the page component and its props |
| **Laravel** | | | |
| [laravel/ai](https://github.com/laravel/ai) | [AI](#ai) | `ai` | agents, steps, tool calls and tokens |
| [Laravel Folio](https://laravel.com/docs/folio) | [Folio](#folio) | `request` | the page file a request matched |
| [Laravel Fortify](https://laravel.com/docs/fortify) | [Fortify](#fortify) | `auth` | two-factor challenges, failed and passed codes |
| [laravel/mcp](https://github.com/laravel/mcp) | [MCP servers](#mcp-servers) | `mcp` | calls to your application's MCP servers |
| [Laravel Octane](https://laravel.com/docs/octane) | [Octane](#octane) | - | a capture per request in a worker |
| [Laravel Pennant](https://laravel.com/docs/pennant) | [Feature flags](#feature-flags) | `features` | every feature check and its value |
| [Laravel Sanctum](https://laravel.com/docs/sanctum) | [Sanctum](#sanctum) | `auth` | the token a request used |
| [Livewire](https://livewire.laravel.com) | [Livewire](#livewire) | `livewire` | components, method calls, property changes and render time |
| **Spatie** | | | |
| [laravel-medialibrary](https://github.com/spatie/laravel-medialibrary) | [Media library](#media-library) | `media` | media added and conversions run |
| [laravel-responsecache](https://github.com/spatie/laravel-responsecache) | [Response cache](#response-cache) | `response_cache` | cache hits and misses for whole responses |

## Laravel

### Request

Section `request`: method, URL, query and input (secrets masked), uploaded files, headers (masked), cookies (the session cookie, the XSRF token, remember-me cookies and secret-looking names masked), IP, user agent, locale, the logged-in user, the matched route with its parameters and middleware, and response status, size and headers. Responses with a capture for their route or content type are kept, both as the capture presents them and raw; other responses only have their size.

Option: `capture.response_types` - content type (wildcards allowed) => capture. By default JSON responses are captured with `JsonCapture`. Add `'text/plain' => TextCapture::class` to keep plain text as it is, or remove an entry to stop keeping that type.

#### Response captures

Some responses read better as something other than their raw body. Map a route name (wildcards allowed) to a class implementing `ExeQue\Monocle\Contracts\ResponseCapture` under `capture.routes`, or a response header under `capture.headers`. A route capture wins over a header capture, which wins over the content type capture. The dashboard shows the capture and the raw body side by side. Livewire updates and Inertia pages come set up - see [Packages](#packages).

```php
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
            'api.orders.*' => OrderResponseCapture::class,
        ],
    ],
],
```

```php
use ExeQue\Monocle\Contracts\ResponseCapture;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderResponseCapture implements ResponseCapture
{
    public function capture(Request $request, Response $response): ?array
    {
        $order = json_decode($response->getContent(), true);

        return [[
            'title' => 'Order '.$order['number'],
            'items' => ['status' => $order['status'], 'lines' => count($order['lines'])],
        ]];
    }
}
```

See [Extending the request lens](writing-a-lens.md#extending-the-request-lens) for writing your own.

The timeline splits the request into phases:

| Phase | Covers |
|---|---|
| `bootstrap` | starting the framework |
| `middleware (request)` | global middleware, before the request reaches the router |
| `routing` | finding the route |
| `handle` | route middleware, the controller and building the response |
| `controller` | the controller itself |
| `terminate` | work done after the response was sent |

### Session

Section `session`: what the session held once the request was handled - the data (secret-looking keys and the CSRF token masked), what the request changed in it (each key with its value before and after), the keys flashed for the next request, the driver and a short hash of the session id.

### Auth

Section `auth`: who the request was authenticated as and how - the guard that resolved the user, logins, logouts, failed attempts and lockouts, registrations, verifications and password resets. Credentials are recorded as the keys that were tried, never their values. The summary shows the user, failed attempts and lockouts.

### Validation

Section `validation`: every validation - form requests, `$request->validate()`, Livewire and your own `Validator::make()` - with where it came from (the form request or Livewire component), the rules per field and, when it failed, each failed rule with its parameters and message. Only field names are recorded, never the values.

A resolver you set with `Validator::resolver()` keeps working.

### Middleware

Every middleware is timed. On the timeline each middleware has a `middleware.request` span for the work it did before passing the request on and a `middleware.response` span for the work it did once the response came back. It also shows whether the middleware stopped the request (for example a redirect or a 403) and with which status. Middleware written as closures is not timed.

Option: `slow_ms` (default 50) - middleware slower than this is flagged.

### Database

Section `queries`: every query with its SQL, bindings, a ready-to-run version with the values filled in, connection, duration, type (select, insert, ...), tables and the transaction it ran in.

Transaction statements - `BEGIN TRANSACTION`, `COMMIT`, `ROLLBACK` and savepoints - are listed in between, in the order they ran. Repeated queries are recognised even when their values differ, so N+1 problems and duplicates show up as warnings with the line that caused them. Transactions appear in the timeline with their outcome (committed or rolled back).

Options: `slow_ms` (70), `n_plus_one_threshold` (3), `bindings` (true), `ignore_tables` (`telescope_*`, `pulse_*`): queries that only touch these tables are left out.

### Eloquent

Sections `models` and `model_changes`: how many models of each class were retrieved, created, updated and deleted, and every write with each changed attribute as `field`, `from` and `to`. Hidden attributes are left out.

### Cache

Section `cache`: hits, misses, writes and forgets with key, store, tags, TTL, size and duration. A key read again in the same capture points at its first read (`repeat_of`). The summary shows the hit rate and the most used keys, and warns when one key is read often - remember such a value for the request with `Cache::memo()` or `once()`.

Options: `repeat_threshold` (default 5) and `repeat_ms` (default 5) - a key read at least this often, taking at least this long in all, gives a warning.

Option: `values` (false) - also record the cached values.

### Redis

Section `redis`: every Redis command with its parameters, connection and duration.

### Views

Section `views`: every rendered view and component with its duration (including the views it renders) and the names of the variables passed to it. Nested views appear nested in the timeline, so a partial rendered in a loop is easy to spot.

Options: `data` (false) - also record the variable values; `repeat_threshold` (10) and `repeat_ms` (20) - warn when a view is rendered at least this often and takes at least this long in all.

### Events

Section `events`: your application's events with their payload and listeners. Framework events are left out.

Option: `ignore` - event names or patterns to leave out.

### Queue

Section `jobs`: jobs dispatched during the capture.

Every queued job is recorded as its own capture when a worker runs it, linked to the request, command or job that dispatched it. Use `monocle-trace` to follow the chain. Jobs that run immediately (the `sync` connection or `dispatchSync()`) appear in the timeline of the capture that ran them.

### Artisan commands

Section `command`: every artisan command is recorded as its own capture, with its arguments, options (secrets masked) and exit code. A command called from somewhere else, like `Artisan::call()` inside a request, appears in that capture's timeline.

### Scheduler

Section `scheduled_tasks` on the `schedule:run` capture: each task with its schedule, outcome (finished, skipped or failed) and runtime.

### HTTP client

Section `http`: outgoing requests made with Laravel's `Http` client - method, URL, status, sizes, duration and failures. Requests sent together with `Http::pool()` or `Http::batch()` share a `pool` number, and the timeline shows them under one pool row. Real calls also record where the time went: DNS lookup, connecting, the TLS handshake, waiting for the first byte, downloading and redirects, with the address connected to and the protocol. A JSON or form body sent is kept as fields, masked like request input. Responses are read with the same captures the request lens uses, chosen by content type under `capture.response_types` (JSON by default), and kept with their raw body; set `bodies` to `true` to also keep bodies no capture handles. Secrets in headers and query strings are masked.

Option: `bodies` (false) - also record request and response bodies.

### Mail and notifications

Section `mail`: sent mail with subject, recipients, attachments and size, and sent notifications with their channel and recipient.

### Filesystem

Section `filesystem`: operations on `Storage` disks - reads, writes, deletes, copies, moves, listings and so on - with path, size, duration and failures.

Nothing is recorded until you choose the disks:

```php
// config/monocle.php
FilesystemLens::class => ['disks' => ['media', 'uploads-*']],   // or ['*'] for every disk
```

Plain PHP file functions like `file_put_contents()` are not recorded.

### Exceptions

Section `exceptions`: every reported exception with its message, the lines of your code it passed through and the exception that caused it.

### Log

Section `log`: everything written to the log, with level and context. Errors are also listed as warnings.

### Authorization

Section `authorization`: every gate and policy check - which ability, for which user, the result and the reason when one was given. Useful when a request ends in a 403.

### Dumps

Section `dumps`: every `dump()` and `dd()` with the value and where it was called. You still see the output as usual.

## Packages

### Inertia

Inertia responses are shown as a card on the Request tab: the component, its props and, on a partial reload, which props were asked for. This works for Inertia visits and for the page embedded in the first load.

### Laravel

#### AI

Section `ai`: calls to `laravel/ai` agents - agent, provider, model, prompt, response, tool calls, tokens, duration and status. The timeline shows each agent with its steps and tool calls; an agent called from inside a tool is shown under that tool.

Option: `max_text` (no limit) - shorten long prompts and responses.

#### Folio

The page file a request matched is shown as the request's action, with the page name, mount path and parameters under `folio`. See [Request](#request).

#### Fortify

Section `auth` also records Fortify's two-factor login: when a user is asked for a code, wrong codes and the code that let them in, plus two-factor being turned on, confirmed or off, recovery codes being generated or used, and password changes. The summary counts failed codes. Recovery codes themselves are never recorded.

#### MCP servers

Section `mcp`: calls to MCP servers your application provides - which tool, resource or prompt, the arguments, duration and whether it failed. Calls to Monocle's own server are not recorded.

#### Octane

Each request in an Octane worker is its own capture, starting before global middleware runs, so the middleware phase is complete.

#### Feature flags

Section `features`: every feature check - the feature, its scope, the value and whether it came from storage or was resolved just now - plus updates and deletions. A feature that is not defined is flagged, since it quietly resolves to false.

#### Sanctum

Section `auth` also records the Sanctum token a request authenticated with: its id, name, abilities and owner. See [Auth](#auth).

### Livewire

Section `livewire`: every component in the request - whether it was mounted or updated, the methods called, properties changed, render time and any exception.

The response of a Livewire update is shown as one card per component on the Request tab: the methods it called with their parameters, the properties that changed, the events it dispatched and what it returned.

### Spatie

#### Media library

Section `media`: media added to models - the model, collection, disk, file and size - and every conversion that ran, with its duration. Conversions also show on the timeline. Conversions that run during a request rather than on the queue are flagged.

#### Response cache

Section `response_cache`: whether the response came from the cache, how old it was and its tags, and when the cache was cleared. A hit is flagged: the controller did not run, so a change to it shows nothing until the entry expires or the cache is cleared.
