<!-- Part of the Monocle documentation - see README.md -->

# What gets recorded

An **capture** is one unit of work: an HTTP request, a queued job, an artisan command or an MCP call. It has a name, a status, a label, metadata, a tree of **spans** (timed intervals) and a number of **sections** (lists of rows). Each section is written by one **lens**.

Every row gets `time` (milliseconds since the capture started) and `origin` (user-land file and line). Nothing is truncated or capped per capture; the only limit is how many captures the store keeps.

The lenses below ship with Monocle. Each is a class in `config/monocle.php` under `lenses`; set it to `false` to turn it off or to an array of options.

## RequestLens

Section `request`. Owns the HTTP capture: starts it, records what came in and went out, lays down the phase spans and persists at terminate.

Recorded: method, url, path, query and input (masked), uploaded files (name, size, mime), headers (masked), cookie names, ip, user agent, ajax / Livewire / Precognition flags, locale, authenticated user (class and id), the matched route (name, action, uri, methods, parameters, middleware, domain), response status, headers, size, content type and redirect target, peak memory.

Phase spans (kind `phase`, siblings at the root of the timeline):

| Phase | From | To |
|---|---|---|
| `bootstrap` | `LARAVEL_START` | application booted |
| `middleware` | booted | routing starts |
| `routing` | `Routing` event | `RouteMatched` |
| `handle` | `RouteMatched` | `RequestHandled` (route middleware, controller, response preparation) |
| `terminate` | `RequestHandled` | terminating callbacks |

A `controller` span (kind `controller`) is derived at finalize: from the moment the innermost middleware called `$next` until the response was first prepared. When a route middleware short-circuits there is no controller span.

The response gets `X-Monocle-Id` and `Server-Timing` headers. `X-Monocle-Label` on the request labels the capture.

## MiddlewareLens

Spans of kind `middleware`, one per class-based middleware, nested like the onion they form. Each has `before_ms` (time until it called `$next`), `after_ms` (time after `$next` returned), `passed` (false when it short-circuited), `short_circuited_with` (the status it returned instead), `parameters` and `group` (`global` or `route`). Terminable middleware get a `middleware.terminate` span.

It works by extending every middleware class in the container with a timing decorator; the pipeline resolves middleware through the container, so no middleware needs to change. Closure middleware is not timed.

Options: `slow_ms` (default 50) - own time above this produces a warning.

## DatabaseLens

Section `queries`. One row per executed query:

| Field | Meaning |
|---|---|
| `n` | row number within the capture |
| `sql`, `bindings`, `sql_with_bindings` | the statement as sent, its bindings, and the two merged for copy-paste |
| `connection`, `driver` | connection name and driver |
| `duration` | milliseconds |
| `type` | `select`, `insert`, `update`, `delete` or `other` |
| `tables` | tables named in the statement |
| `shape`, `shape_hash` | the SQL with literals replaced by `?` and IN-lists collapsed, so repeated queries group together |
| `transaction_id` | the `db.transaction` span the query ran in |
| `slow` | `duration >= slow_ms` |
| `duplicate_of` | row number of an earlier identical query (same SQL and bindings) |

Transactions become spans of kind `db.transaction` with `outcome` (`committed`, `rolled_back`, `left_open`) and nesting level for savepoints.

Summary warnings: the three slowest slow queries, the number of duplicates, and N+1 candidates (a select shape repeated at least `n_plus_one_threshold` times, with its origin).

Options: `slow_ms` (70), `n_plus_one_threshold` (3), `bindings` (true - set false to leave bindings out).

## CacheLens

Section `cache`. One row per operation: `type` (`hit`, `miss`, `write`, `forget`, `flush`, `write_failed`, `forget_failed`), `key`, `store`, `tags`, `ttl`, `value_size_bytes` (serialized length) and `duration` where the store emits the start events Laravel provides.

Summary: hits, misses, hit rate, writes, forgets, total ms and the five hottest keys.

Options: `values` (false) - also store the values themselves.

## QueueLens

Section `jobs` plus captures of type `job`. Two sides:

**Dispatch side** - every queued job is a row on the capture that dispatched it: `job`, `connection`, `queue`, `delay`, `job_id`, `batch_id`, `chained` (remaining chain length) and `sync` (ran inline). The payload gets a `monocle` key carrying the dispatching capture's `parent_id` and `trace_id`, so the link survives the queue.

**Worker side** - every processed job becomes its own capture: name is the job class, status `processed`, `failed` or `exception`, `parent_id`/`trace_id` from the payload. Section `job` has class, connection, queue, `job_id`, `attempts`, `max_tries`, `batch_id`, `chained_remaining`, `payload_bytes` and, on failure, `exception` (class, message, file). Everything the job did - queries, cache, spans - is recorded on that capture. `monocle-trace` walks the whole tree.

Jobs that run inline (the `sync` connection, `dispatchSync()`, `dispatchNow()`) become spans of kind `job` inside the current capture instead. A bus pipe records non-queueable commands too.

Long-running workers themselves (`queue:work`, `horizon`) are excluded as commands; their jobs are not.

## CommandLens

Section `command` plus captures of type `command`. Every artisan command run becomes a capture named `artisan <command>` with status = exit code and a `command` section: `name`, `arguments`, `options` (masked), `exit_code`. Phase spans `bootstrap` (from `LARAVEL_START`) and `command`.

A command invoked while another capture is active - `Artisan::call()` inside a request or a command - becomes a span of kind `command` with `exit_code` instead. Commands matching `monocle.except.commands` (Monocle's own, Boost, workers, the scheduler loop) are never recorded.

## ScheduleLens

Section `scheduled_tasks` on the `schedule:run` capture: one row per task event with `command`, `expression`, `description`, `outcome` (`starting`, `finished`, `finished_in_background`, `skipped`, `failed`), `runtime_ms` and `exception`. Tasks run as subprocesses, so what they did themselves lands in their own command captures.

## Lenses on the way

Eloquent model counts, Redis commands, events, log captures, views (timed per render), mail and notifications, outgoing HTTP calls, exceptions, authorization checks, Livewire components, filesystem operations (per disk, opt-in), `laravel/ai` agents, steps and tool calls, `dump()`/`dd()` captures and the application's own MCP servers. Each arrives as its own lens with its own section; the agent discovers them through `available_sections` in `monocle-show`.
