<!-- Part of the Monocle documentation - see README.md -->

# What gets recorded

A **capture** is one request, queued job, artisan command or MCP call. Each capture has a timeline of timed **spans** and a number of **sections** with rows. Each section is filled by a **lens**.

Every row records when it happened and the `origin` - the file and line in your code that caused it.

Turn lenses on, off or change their options in [`config/monocle.php`](configuration.md#lenses).

Lenses report two kinds of problems in the summary: **errors** for things that failed and **warnings** for things worth a look. Each lens below says which it reports; a response with a 5xx status is an error too. Warnings point at your code. Slow or repeated work that comes from a package on its own - a slow query a library runs, a package's middleware - is not flagged. Possible N+1 queries, slow cache calls and slow processes are flagged wherever they come from.

## At a glance

### Laravel

| Lens | Section | Records |
|---|---|---|
| [Request](#request) | `request` | method, URL, input, headers, cookies, route, response and phases |
| [Session](#session) | `session` | session contents and what the request changed |
| [Auth](#auth) | `auth` | the user, logins, failed attempts and lockouts |
| [Validation](#validation) | `validation` | every validation, its rules and the rules that failed |
| [Middleware](#middleware) | `middleware` | each middleware before and after the controller |
| [Database](#database) | `queries` | every query, transactions, N+1 and slow queries |
| [Eloquent](#eloquent) | `models`, `model_changes` | models loaded and every changed attribute |
| [Cache](#cache) | `cache` | hits, misses, writes and repeated reads |
| [Redis](#redis) | `redis` | every Redis command |
| [Views](#views) | `views` | every view and component with its duration |
| [Events](#events) | `events` | your events and their listeners |
| [Queue](#queue) | `jobs` | jobs dispatched, and each job as its own capture |
| [Artisan commands](#artisan-commands) | `command`, `commands` | arguments, options and exit code; commands called during a capture |
| [Scheduler](#scheduler) | `scheduled_tasks` | each scheduled task and its outcome |
| [HTTP client](#http-client) | `http` | outgoing calls made with the `Http` client |
| [Mail and notifications](#mail-and-notifications) | `mail` | mail and notifications sent |
| [Filesystem](#filesystem) | `filesystem` | operations on the `Storage` disks you choose |
| [Processes](#processes) | `processes` | external commands run with the `Process` facade |
| [Exceptions](#exceptions) | `exceptions` | reported exceptions and where they came from |
| [Log](#log) | `log` | everything written to the log |
| [Authorization](#authorization) | `authorization` | every gate and policy check |
| [Dumps](#dumps) | `dumps` | every `dump()` and `dd()` |

### Packages

These record something only when the package is installed; otherwise they do nothing.

| Package | Lens | Section | Records |
|---|---|---|---|
| [Filament](https://filamentphp.com) | [Filament](#filament) | `filament`, `filament_context`, `livewire` | actions and how they ended, notifications, table filters, search and sort, the panel, page and resource |
| [franbarbalopez/mirror](https://github.com/franbarbalopez/mirror) | [Impersonation](#impersonation) | `auth` | who is impersonating the user |
| [Inertia](https://inertiajs.com) | [Inertia](#inertia) | `inertia`, `inertia_pages` | the page, every prop with its type and size, deferred props and partial reloads |
| [lab404/laravel-impersonate](https://github.com/404labfr/laravel-impersonate) | [Impersonation](#impersonation) | `auth` | who is impersonating the user |
| [Livewire](https://livewire.laravel.com) | [Livewire](#livewire) | `livewire` | components, method calls, property changes and render time |
| [Prism](https://prismphp.com) | [Prism](#prism) | `prism` | text, structured, stream, embeddings, image and audio calls with tokens |
| **Laravel** | | | |
| [laravel/ai](https://github.com/laravel/ai) | [AI](#ai) | `ai`, `ai_tools` | agents, steps, tool calls with their time, and tokens |
| [Laravel Folio](https://laravel.com/docs/folio) | part of [Request](#request), see [Folio](#folio) | `request` | the page file a request matched |
| [Laravel Fortify](https://laravel.com/docs/fortify) | [Fortify](#fortify) | `auth` | two-factor challenges, failed and passed codes |
| [laravel/mcp](https://github.com/laravel/mcp) | [MCP servers](#mcp-servers) | `mcp` | calls to your application's MCP servers |
| [Laravel Octane](https://laravel.com/docs/octane) | part of [Request](#request), see [Octane](#octane) | - | a capture per request in a worker |
| [Laravel Nova](https://nova.laravel.com) | [Impersonation](#impersonation) | `auth` | who is impersonating the user |
| [Laravel Pennant](https://laravel.com/docs/pennant) | [Feature flags](#feature-flags) | `features` | every feature check and its value |
| [Laravel Sanctum](https://laravel.com/docs/sanctum) | [Sanctum](#sanctum) | `auth` | the token a request used |
| **Spatie** | | | |
| [laravel-medialibrary](https://github.com/spatie/laravel-medialibrary) | [Media library](#media-library) | `media` | media added and conversions run |
| [laravel-responsecache](https://github.com/spatie/laravel-responsecache) | [Response cache](#response-cache) | `response_cache` | cache hits and misses for whole responses |
| [stechstudio/filament-impersonate](https://github.com/stechstudio/filament-impersonate) | [Impersonation](#impersonation) | `auth` | who is impersonating the user |

## Laravel

### Request

Section `request`: method, URL, query and input (secrets masked), uploaded files, headers (masked), cookies (the session cookie, the XSRF token, remember-me cookies and secret-looking names masked), IP, user agent, locale, the logged-in user, the matched route with its parameters and middleware, and response status, size and headers. Responses with a capture for their route, a response header or their content type are kept, both as the capture presents them and raw; other responses only have their size.

A response with a 5xx status is reported as an error.

Option: `capture` - which responses are kept, and how. By default:

| Key | Default |
|---|---|
| `response_types` | `application/json` and `application/*+json` with `JsonCapture`, `text/html` with `InertiaCapture` (only Inertia pages are kept) |
| `headers` | `X-Inertia` with `InertiaCapture` |
| `routes` | `*livewire.update` with `LivewireUpdateCapture` |

Each key replaces its default when you set it, so list the defaults you want to keep. Add `'text/plain' => TextCapture::class` to `response_types` to keep plain text as it is, or leave an entry out to stop keeping that type.

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

Section `auth`: who the request was authenticated as and how - the guard that resolved the user, logins, logouts, failed attempts and lockouts, registrations, verifications and password resets. Credentials are recorded as the keys that were tried, never their values. The summary shows the user, failed attempts and lockouts. With [Fortify](#fortify), [Sanctum](#sanctum) or an [impersonation](#impersonation) package installed, their rows are added to this section too.

Warning: a login locked out after too many attempts.

### Validation

Section `validation`: every validation - form requests, `$request->validate()`, Livewire and your own `Validator::make()` - with where it came from (the form request or Livewire component), the rules per field and, when it failed, each failed rule with its parameters and message. The input of the validated fields is kept too, with secrets masked like request input, uploaded files described by name, size and type, and long values cut short. Rule objects and closures are recorded as the class you wrote (with its public properties) or the file and line of the closure.

A resolver you set with `Validator::resolver()` keeps working.

### Middleware

Section `middleware`: one row per middleware, in the order the request passed through them - the middleware, its group (global or route), its parameters, the time it took before passing the request on and after the response came back, its own time in all, whether it passed the request on and, when it stopped the request (for example a redirect or a 403), with which status. On the timeline each middleware has a `middleware.request` span for the work before and a `middleware.response` span for the work after. Middleware written as closures is not timed.

Warnings: middleware that took at least `slow_ms` of its own time, and middleware that stopped the request.

Option: `slow_ms` (default 50) - middleware slower than this is flagged.

The Middleware tab shows each middleware's time before (`Before`) and after (`After`) the controller and its own time, and can be grouped by group. The overview shows the middleware count and the total middleware time at the top.

### Database

Section `queries`: every query with its SQL, bindings, a ready-to-run version with the values filled in, connection, duration, type (select, insert, ...), tables and the transaction it ran in.

Transaction statements - `BEGIN TRANSACTION`, `COMMIT`, `ROLLBACK` and savepoints - are listed in between, in the order they ran. Repeated queries are recognised even when their values differ, so N+1 problems and duplicates show up as warnings with the line that caused them. A select that looks like an N+1 carries `n_plus_one`: how often its shape ran. The exact same query with the same values counts as a duplicate (`duplicate_of` points at the first one, which carries `repeated`: how many later queries repeated it), not as an N+1. Transactions appear in the timeline with their outcome (committed, rolled back, or left open when the capture ended first).

Warnings: slow queries, duplicate queries and possible N+1 queries.

Options:

- `slow_ms` (default 70) - a query taking at least this long is slow.
- `n_plus_one_threshold` (default 3) - how often a select's shape must run to count as a possible N+1.
- `bindings` (default true) - false leaves the bindings out of the rows.
- `ignore_tables` (default `telescope_*`, `pulse_*`) - queries that only touch these tables are left out.

### Eloquent

Sections `models` and `model_changes`: how many models of each class were retrieved, created, updated, deleted, restored and force deleted, and every write with each changed attribute as `field`, `from` and `to`. Hidden attributes are left out. A write made in a transaction that was rolled back is marked `rolled_back`.

### Cache

Section `cache`: hits, misses, writes, forgets, flushes and failed operations with key, store, tags, TTL, value size and, when the store reports when a call started, duration. A key read again in the same capture points at its first read (`repeat_of`). The summary shows the hit rate and the most used keys.

Warnings: slow cache calls, and keys read over and over - remember such a value for the request with `Cache::memo()` or `once()`.

Options:

- `slow_ms` (default 20) - a cache call taking at least this long gives a warning, wherever it comes from.
- `repeat_threshold` (default 5) and `repeat_ms` (default 0) - a key read at least this often, taking at least this long in all, gives a warning.
- `values` (default false) - also record the cached values.

### Redis

Section `redis`: every Redis command with its parameters, connection and duration.

### Views

Section `views`: every rendered view and component with its duration (including the views it renders) and the names of the variables passed to it. Nested views appear nested in the timeline, so a partial rendered in a loop is easy to spot.

Warning: a view rendered many times.

Options: `engines` (`['blade', 'php', 'file']`) - the view engines to time; `data` (false) - also record the variable values; `repeat_threshold` (10) and `repeat_ms` (20) - warn when a view is rendered at least this often and takes at least this long in all.

### Events

Section `events`: your application's events with their payload, listeners and whether they broadcast. Framework events are left out.

Option: `ignore` - event names or patterns to leave out. The default is `Illuminate\*`, `Laravel\*`, `Livewire\*`, `eloquent.*`, `composing:*`, `creating:*`, `bootstrapping:*`, `bootstrapped:*` and `locale.*`. Setting the option replaces this list, so include the defaults you want to keep.

### Queue

Section `jobs`: jobs dispatched during the capture - class, connection, queue, delay, job id, batch, chain length and whether it ran inline. For a queued job, `run` links to the job's own capture once a worker has run it (*pending* until then). The summary counts the jobs dispatched and those that ran inline.

On the job's own capture, section `job` holds its class, connection, queue, attempt and maximum tries, batch, remaining chain, payload size and, when it failed, the exception.

Every queued job is recorded as its own capture when a worker runs it, linked to the request, command or job that dispatched it. Use `monocle-trace` to follow the chain. Jobs that run immediately (the `sync` connection or `dispatchSync()`) appear in the timeline of the capture that ran them.

Error: a job that failed, on the job's own capture.

### Artisan commands

Section `command`: every artisan command is recorded as its own capture, with its arguments, options (secrets masked) and exit code. A command called from somewhere else, like `Artisan::call()` inside a request, job or another command, appears in that capture's timeline and as a row in section `commands`: name, class, arguments, options (masked), exit code, duration and the line that called it. A request or job that called commands gets a **Commands** tab; on a command capture they are listed on its own tab. A non-zero exit code marks the row as failed and is reported as an error. Totals: `commands_ms` (time spent), `commands` and `commands_failed`.

### Scheduler

Section `scheduled_tasks` on the `schedule:run` capture: each task with its schedule, outcome (finished, skipped or failed) and runtime.

Error: a task that failed.

### HTTP client

Section `http`: outgoing requests made with Laravel's `Http` client - method, URL, status, sizes, duration and failures. Requests sent together with `Http::pool()` or `Http::batch()` share a `pool` number, and the timeline shows them under one pool row. Real calls also record where the time went: DNS lookup, connecting, the TLS handshake, waiting for the first byte, downloading and redirects, with the address connected to and the protocol. A JSON or form body sent is kept as fields, masked like request input. Responses are read with the same captures the request lens uses, chosen by content type, and kept with their raw body. Secrets in headers and query strings are masked.

Error: a call that got a 4xx or 5xx response or no response at all.

Options:

- `capture.response_types` - content type (wildcards allowed) => capture. By default `application/json` and `application/*+json` with `JsonCapture`. Setting it replaces the default.
- `bodies` (default false) - also keep the raw request body, and the raw response body of responses no capture handles.

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

Error: an operation that failed.

### Processes

Section `processes`: external commands started with Laravel's `Process` facade - `run()`, `start()`, pools and pipes - with the command line, working directory, exit code, whether it succeeded or timed out, duration and the size of its output and error output. Each process is a `process` span on the timeline. A process started in the background (`start()` or a pool) runs from the moment it started until you waited for it; one nobody waited for ends with the capture and has no exit code. `Process::fake()` keeps working, and faked processes are recorded too.

Processes started without the facade - Symfony's `Process` used directly, `exec()`, `shell_exec()`, `proc_open()` and so on - are not recorded.

Error: a process that exited with a non-zero code, timed out or could not be started.

Warning: a process slower than `slow_ms`.

Options:

- `slow_ms` (default 1000) - a process taking at least this long gives a warning.

### Exceptions

Section `exceptions`: every reported exception with its message, the lines of your code it passed through and the exception that caused it. Each one is reported as an error.

### Log

Section `log`: everything written to the log, with level and context. Entries at an error level or above are reported as errors.

### Authorization

Section `authorization`: every gate and policy check - which ability, for which user, the result and the reason when one was given. Useful when a request ends in a 403.

Warning: a check that was denied.

### Dumps

Section `dumps`: every `dump()` and `dd()` with the value and where it was called. You still see the output as usual. A capture ended by `dd()` is still stored, marked as ended at shutdown.

Warning: `dd()` ended the process before the response was sent.

## Packages

Each package lens is listed by class in `config/monocle.php` under `ExeQue\Monocle\Lenses\Packages\{Author}\`, and does nothing until its package is installed. Lenses that add to another lens's section, such as Fortify or the impersonation lenses, are turned off by their own class.

### Filament

Section `filament`: what Filament did during the request, one row per thing, grouped by kind:

- `action` - an action mounted or run on a page, table or relation manager: its name, the component, the status (`success`, `failed`, `halted` when it was halted or cancelled, `invalid` when its form failed validation, with the fields that failed, `error` when it threw, `modal opened` when it opened its modal and waits for the user, `stopped` when it did not run, such as a disabled or unauthorized action), its arguments, the record of a table action, whether it is a bulk action, the notifications it sent and how long it took. The data entered in its form is not recorded.
- `notification` - a notification sent with `Filament\Notifications\Notification`: title, status, body and channel - `flash` for `send()`, `database` for `sendToDatabase()`, `broadcast` for `broadcast()` - and the action that sent it. Database and broadcast notifications are queued, so they show up where they are delivered, such as the capture of the queued job.
- `table` - every table rendered: the active filters, the search, column searches, sort, grouping, the active tab, the page and records per page, the records shown and the total, and whether loading was deferred.
- `record` - a record created or updated on a resource's create or edit page.

Section `filament_context`: the panel (its id, path and auth guard), the tenant, the page, its resource and the record it shows. The Filament tab shows these facts above the rows.

With Livewire recorded too, the rows of Filament components in the `livewire` section say what they are to Filament - `resource page`, `page`, `relation manager`, `widget`, `table` or `component` - with the panel, the resource and the record (`owner_record` on a relation manager).

Needs `filament/filament`. Lenses: `Filament\FilamentLens` (sections `filament` and `filament_context`) and `Filament\FilamentLivewireLens` (adds to `livewire`).

Errors: an action that threw an exception, and an action that ended with `failure()`. Warning: a table without pagination that loaded more than `large_table_records` records.

Option: `large_table_records` (500).

### Inertia

Section `inertia`: one row per prop of an Inertia page - its type (plain, closure, always, optional, lazy, defer, merge, scroll, once), whether it is shared, whether it was sent, its size as JSON, its defer group and merge strategy, and, for a prop that was not sent, why: deferred, left out by a partial reload, optional, already loaded once, or rescued after it failed. Props resolved by a closure in your code - deferred, optional, lazy or a plain closure - show where the closure is defined, linked to your editor. Expanding a prop that was sent shows its value, with secrets masked like request input. The time each of those closures took to resolve is shown with the prop and as a span on the timeline, with the queries it ran under it; a deferred prop is timed on the request that loads it.

Section `inertia_pages`: the page itself - component, URL, asset version, whether it was a first load or an Inertia visit, the props a partial reload asked for (only or except), the deferred groups, the size of the whole page and whether history is encrypted or cleared. The Inertia tab shows these facts above the props, and groups the props by type or by scope (shared or page).

Needs `inertiajs/inertia-laravel`.

Warnings: a prop bigger than `large_prop_bytes`, a page bigger than `large_page_bytes`, and a shared prop sent on a partial reload that did not ask for it (such as a reload with only an except list). Wrap such a prop in `Inertia::always()` if it is meant to be sent every time.

Options: `large_prop_bytes` (50000) and `large_page_bytes` (200000); `max_value_bytes` (20000) - a prop value larger than this is shown as its size instead of its value.

Inertia responses are also shown as a card on the Request tab: the component, its props and, on a partial reload, which props were asked for. This works for Inertia visits and for the page embedded in the first load.

### Prism

Section `prism`: calls made with `prism-php/prism` - operation (text, structured, stream, embeddings, images, moderation, text to speech, speech to text), provider, model, prompt, response, structured output, finish reason, steps, tool calls, the tools offered, tokens, duration and status. Each call is shown in the timeline; a stream shows its steps. A stream that is not read to the end has the status `abandoned`. The HTTP requests Prism sends to the provider are in the `http` section too.

Needs `prism-php/prism`. Calls made with `Prism::fake()` are recorded as well.

Error: a call that failed, or a tool that failed during a stream.

Option: `max_text` (no limit) - shorten long prompts and responses.

### Laravel

#### AI

Section `ai`: calls to `laravel/ai` agents - agent, provider, model, prompt, response, tool calls, tokens, duration and status. The timeline shows each agent with its steps and tool calls; an agent called from inside a tool is shown under that tool. Section `ai_tools`, on the same tab: one row per tool call - tool, agent, status, arguments, result or exception, and how long it took. The overview adds the time spent in tools and the number of tool calls.

Error: an agent or tool call that failed.

Needs `laravel/ai`.

Option: `max_text` (no limit) - shorten long prompts and responses.

#### Folio

The page file a request matched is shown as the request's action, with the page name, mount path and parameters under `folio`. Built into the [request lens](#request) - nothing to enable.

#### Fortify

Section `auth` also records Fortify's two-factor login: when a user is asked for a code, wrong codes and the code that let them in, plus two-factor being turned on, confirmed or off, recovery codes being generated or used, and password changes. The summary counts failed codes. Recovery codes themselves are never recorded.

Needs `laravel/fortify`. Lens: `Laravel\FortifyLens`.

#### Impersonation

With lab404/laravel-impersonate, stechstudio/filament-impersonate, Laravel Nova or franbarbalopez/mirror, section `auth` also records an impersonation starting and ending, with the impersonated user and who impersonated them. While an impersonation lasts, the authenticated user is marked with the id and guard of the user impersonating them, the summary shows it, and the Request tab shows it next to the user. No user is loaded from the database to find out. The capture is tagged "impersonated by ..." in the dashboard's header and list.

Lenses: `Lab404\ImpersonateLens`, `STS\FilamentImpersonateLens`, `Laravel\NovaLens` and `Mirror\MirrorLens`.

#### MCP servers

Section `mcp`: calls to MCP servers your application provides - which tool, resource or prompt, the arguments, duration and whether it failed. Servers listed in `mcp.except_servers` (Monocle's own and Laravel Boost's by default) are not recorded - see [Configuration](configuration.md#mcp). Over HTTP a call is part of the request capture; over stdio each call is its own capture of type `mcp`.

Needs `laravel/mcp`.

Error: a call that failed.

#### Octane

Each request in an Octane worker is its own capture, starting before global middleware runs, so the middleware phase is complete. Built into the [request lens](#request) - nothing to enable.

#### Feature flags

Section `features`: every feature check - the feature, its scope, the value and whether it came from storage or was resolved just now - plus updates and deletions. A feature that is not defined gives a warning, since it quietly resolves to false.

Needs `laravel/pennant`.

#### Sanctum

Section `auth` also records the Sanctum token a request authenticated with: its id, name, abilities and owner. See [Auth](#auth).

Needs `laravel/sanctum`. Lens: `Laravel\SanctumLens`.

### Livewire

Section `livewire`: every component in the request - whether it was mounted or updated, the methods called, properties changed, render time and any exception. A component that threw an exception is reported as an error.

Needs `livewire/livewire`.

The response of a Livewire update is shown as one card per component on the Request tab: the methods it called with their parameters, the properties that changed, the events it dispatched and what it returned.

### Spatie

#### Media library

Section `media`: media added to models - the model, collection, disk, file and size - and every conversion that ran, with its duration. Conversions also show on the timeline. Conversions that run during a request rather than on the queue give a warning.

Needs `spatie/laravel-medialibrary`.

#### Response cache

Section `response_cache`: whether the response came from the cache, how old it was and its tags, and when the cache was cleared. A hit gives a warning: the controller did not run, so a change to it shows nothing until the entry expires or the cache is cleared.

Needs `spatie/laravel-responsecache`.
