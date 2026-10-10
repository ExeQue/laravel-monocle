<!-- Part of the Monocle documentation - see README.md -->

# What gets recorded

A **capture** is one request, queued job, artisan command or MCP call. Each capture has a timeline of timed **spans** and a number of **sections** with rows. Each section is filled by a **lens**.

Every row records when it happened and the `origin` - the file and line in your code that caused it.

Turn lenses on, off or change their options in [`config/monocle.php`](configuration.md#lenses).

## Request

Section `request`: method, URL, query and input (secrets masked), uploaded files, headers (masked), cookie names, IP, user agent, locale, the logged-in user, the matched route with its parameters and middleware, response status, size and headers, and peak memory.

The timeline splits the request into phases:

| Phase | Covers |
|---|---|
| `bootstrap` | starting the framework |
| `middleware` | global middleware |
| `routing` | finding the route |
| `handle` | route middleware, the controller and building the response |
| `controller` | the controller itself |
| `terminate` | work done after the response was sent |

## Middleware

Every middleware is timed. On the timeline each middleware has a `before` span for the work it did before passing the request on and an `after` span for the work it did once the response came back, so you can see where in the request each part ran. It also shows whether the middleware stopped the request (for example a redirect or a 403) and with which status. Middleware written as closures is not timed.

Option: `slow_ms` (default 50) - middleware slower than this is flagged.

## Database

Section `queries`: every query with its SQL, bindings, a ready-to-run version with the values filled in, connection, duration, type (select, insert, ...), tables and the transaction it ran in.

Repeated queries are recognised even when their values differ, so N+1 problems and duplicates show up as warnings with the line that caused them. Transactions appear in the timeline with their outcome (committed or rolled back).

Options: `slow_ms` (70), `n_plus_one_threshold` (3), `bindings` (true).

## Cache

Section `cache`: hits, misses, writes and forgets with key, store, tags, TTL, size and duration. The summary shows the hit rate and the most used keys.

Option: `values` (false) - also record the cached values.

## Eloquent

Sections `models` and `model_changes`: how many models of each class were retrieved, created, updated and deleted, and the changed attributes of every write. Hidden attributes are left out.

## Redis

Section `redis`: every Redis command with its parameters, connection and duration.

## Events

Section `events`: your application's events with their payload and listeners. Framework events are left out.

Option: `ignore` - event names or patterns to leave out.

## Log

Section `log`: everything written to the log, with level and context. Errors are also listed as warnings.

## Views

Section `views`: every rendered view and component with its duration and the names of the variables passed to it. Nested views appear nested in the timeline, so a partial rendered in a loop is easy to spot.

Options: `data` (false) - also record the variable values; `repeat_threshold` (10) - warn when a view is rendered this many times.

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
