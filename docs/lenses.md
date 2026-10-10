<!-- Part of the Monocle documentation - see README.md -->

# What gets recorded

A **capture** is one request, queued job, artisan command or MCP call. Each capture has a timeline of timed **spans** and a number of **sections** with rows. Each section is filled by a **lens**.

Every row records when it happened and the `origin` - the file and line in your code that caused it.

Turn lenses on, off or change their options in [`config/monocle.php`](configuration.md#lenses).

Warnings point at your code. Slow or repeated work that comes from a package on its own - a slow query a library runs, a package's middleware - is not flagged; possible N+1 queries are flagged wherever they come from.

## Request

Section `request`: method, URL, query and input (secrets masked), uploaded files, headers (masked), cookies (the session cookie, the XSRF token and secret-looking names masked), IP, user agent, locale, the logged-in user, the matched route with its parameters and middleware, and response status, size and headers. Responses with a capture for their route or content type are kept, both as the capture presents them and raw; other responses only have their size.

Option: `capture.response_types` - content type (wildcards allowed) => capture. By default JSON responses are captured with `JsonCapture`. Add `'text/plain' => TextCapture::class` to keep plain text as it is, or remove an entry to stop keeping that type.

### Response captures

Some responses read better as something other than their raw body. Map a route name (wildcards allowed) to a class implementing `ExeQue\Monocle\Contracts\ResponseCapture` under `capture.routes`, or a response header under `capture.headers`. A route capture wins over a header capture, which wins over the content type capture. The dashboard shows the capture and the raw body side by side. Livewire updates come set up: one card per component with the methods it called, the properties that changed and the events it dispatched. Inertia pages do too: the component, its props and, on a partial reload, which props were asked for - both for Inertia visits and for the page embedded in a first load.

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

Each card has a `title`, an optional `subtitle` and named `items`. A list of `field`/`from`/`to` entries shows as a table of changes. Return `null` when the response is not one the capture understands; nothing is kept then.

The timeline splits the request into phases:

| Phase | Covers |
|---|---|
| `bootstrap` | starting the framework |
| `middleware (request)` | global middleware, before the request reaches the router |
| `routing` | finding the route |
| `handle` | route middleware, the controller and building the response |
| `controller` | the controller itself |
| `terminate` | work done after the response was sent |


## Auth

Section `auth`: who the request was authenticated as and how - the guard that resolved the user, logins, logouts, failed attempts and lockouts, registrations, verifications and password resets. With Laravel Sanctum, the token used, with its name and abilities. Credentials are recorded as the keys that were tried, never their values. The summary shows the user, failed attempts and lockouts.

## Feature flags

Section `features` (with [Laravel Pennant](https://laravel.com/docs/pennant)): every feature check - the feature, its scope, the value and whether it came from storage or was resolved just now - plus updates and deletions. A feature that is not defined is flagged, since it quietly resolves to false.

## Response cache

Section `response_cache` (with [spatie/laravel-responsecache](https://github.com/spatie/laravel-responsecache)): whether the response came from the cache, how old it was and its tags, and when the cache was cleared. A hit is flagged: the controller did not run, so a change to it shows nothing until the entry expires or the cache is cleared.

## Session

Section `session`: what the session held once the request was handled - the data (secret-looking keys and the CSRF token masked), what the request changed in it (each key with its value before and after), the keys flashed for the next request, the driver and a short hash of the session id.

## Middleware

Every middleware is timed. On the timeline each middleware has a `middleware.request` span for the work it did before passing the request on and a `middleware.response` span for the work it did once the response came back. It also shows whether the middleware stopped the request (for example a redirect or a 403) and with which status. Middleware written as closures is not timed.

Option: `slow_ms` (default 50) - middleware slower than this is flagged.

## Database

Section `queries`: every query with its SQL, bindings, a ready-to-run version with the values filled in, connection, duration, type (select, insert, ...), tables and the transaction it ran in.

Repeated queries are recognised even when their values differ, so N+1 problems and duplicates show up as warnings with the line that caused them. Transactions appear in the timeline with their outcome (committed or rolled back).

Options: `slow_ms` (70), `n_plus_one_threshold` (3), `bindings` (true).

## Cache

Section `cache`: hits, misses, writes and forgets with key, store, tags, TTL, size and duration. A key read again in the same capture points at its first read (`repeat_of`). The summary shows the hit rate and the most used keys, and warns when one key is read often - remember such a value for the request with `Cache::memo()` or `once()`.

Options: `repeat_threshold` (default 5) and `repeat_ms` (default 5) - a key read at least this often, taking at least this long in all, gives a warning.

Option: `values` (false) - also record the cached values.

## Eloquent

Sections `models` and `model_changes`: how many models of each class were retrieved, created, updated and deleted, and every write with each changed attribute as `field`, `from` and `to`. Hidden attributes are left out.

## Redis

Section `redis`: every Redis command with its parameters, connection and duration.

## Events

Section `events`: your application's events with their payload and listeners. Framework events are left out.

Option: `ignore` - event names or patterns to leave out.

## Log

Section `log`: everything written to the log, with level and context. Errors are also listed as warnings.

## Views

Section `views`: every rendered view and component with its duration and the names of the variables passed to it. Nested views appear nested in the timeline, so a partial rendered in a loop is easy to spot.

Options: `data` (false) - also record the variable values; `repeat_threshold` (10) and `repeat_ms` (20) - warn when a view is rendered at least this often and takes at least this long in all.

## Mail and notifications

Section `mail`: sent mail with subject, recipients, attachments and size, and sent notifications with their channel and recipient.

## HTTP client

Section `http`: outgoing requests made with Laravel's `Http` client - method, URL, status, sizes, duration and failures. Secrets in headers and query strings are masked.

Option: `bodies` (false) - also record request and response bodies.

## Queue

Section `jobs`: jobs dispatched during the capture.

Every queued job is recorded as its own capture when a worker runs it, linked to the request, command or job that dispatched it. Use `monocle-trace` to follow the chain. Jobs that run immediately (the `sync` connection or `dispatchSync()`) appear in the timeline of the capture that ran them.

## Artisan commands

Section `command`: every artisan command is recorded as its own capture, with its arguments, options (secrets masked) and exit code. A command called from somewhere else, like `Artisan::call()` inside a request, appears in that capture's timeline.

## Scheduler

Section `scheduled_tasks` on the `schedule:run` capture: each task with its schedule, outcome (finished, skipped or failed) and runtime.

## Exceptions

Section `exceptions`: every reported exception with its message, the lines of your code it passed through and the exception that caused it.

## Authorization

Section `authorization`: every gate and policy check - which ability, for which user, the result and the reason when one was given. Useful when a request ends in a 403.

## Livewire

Section `livewire`: every component in the request - whether it was mounted or updated, the methods called, properties changed, render time and any exception.

## Dumps

Section `dumps`: every `dump()` and `dd()` with the value and where it was called. You still see the output as usual.

## MCP servers

Section `mcp`: calls to MCP servers your application provides - which tool, resource or prompt, the arguments, duration and whether it failed. Calls to Monocle's own server are not recorded.

## Filesystem

Section `fs`: operations on `Storage` disks - reads, writes, deletes, copies, moves, listings and so on - with path, size, duration and failures.

Nothing is recorded until you choose the disks:

```php
// config/monocle.php
FilesystemLens::class => ['disks' => ['media', 'uploads-*']],   // or ['*'] for every disk
```

Plain PHP file functions like `file_put_contents()` are not recorded.

## AI

Section `ai`: calls to `laravel/ai` agents - agent, provider, model, prompt, response, tool calls, tokens, duration and status. The timeline shows each agent with its steps and tool calls; an agent called from inside a tool is shown under that tool.

Option: `max_text` (no limit) - shorten long prompts and responses.
