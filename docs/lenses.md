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

## LivewireLens

Section `livewire`, only when Livewire is installed. One row per component touched by the request: `component`, `class`, `id`, `lifecycle` (`mount` for the initial render, `update` for subsequent requests), `calls` (method, params, duration), `updates` (property path and value), `render_ms`, `html_bytes` and `exception`. Each component is also a `livewire` span in the timeline, so the views it renders nest under it.

## DumpLens

Section `dumps`. Every `dump()` and `dd()`: the value as text, its type, the label and `is_dd`. The value is still handed to whatever dumper was registered before, so the developer sees it as usual. `dd()` ends the process; the shutdown handler stores the capture with `ended_by: shutdown`, and the summary warns about it.

## McpLens

Section `mcp` plus captures of type `mcp`. Records calls to the MCP servers the application itself exposes (not Monocle's own server, nor Boost's - see `monocle.mcp.except_servers`): `server`, JSON-RPC `method`, `primitive` (tool, resource or prompt name), `arguments`, `duration`, `is_error`, `streamed_chunks` and `exception`. Over HTTP (`Mcp::web()`) the call is a row and an `mcp` span on the request capture; over stdio (`Mcp::local()`) every JSON-RPC request becomes its own capture named `server: method primitive`.

## FilesystemLens

Section `fs`. Laravel fires no events for `Storage`, so this lens replaces the filesystem manager with one that wraps the Flysystem adapter of every disk selected in its options. Nothing is recorded until disks are named; wildcards work:

```php
// config/monocle.php
FilesystemLens::class => ['disks' => ['media', 'uploads-*']],   // or ['*'] for every disk
```

Rows: `disk`, `driver`, `op` (`read`, `readStream`, `write`, `writeStream`, `delete`, `deleteDirectory`, `createDirectory`, `fileExists`, `directoryExists`, `listContents`, `move`, `copy`, `mimeType`, `fileSize`, `lastModified`, `visibility`, `setVisibility`, `checksum`), `path`, `destination`, `bytes`, `duration`, `ok` and the `exception` on failure. Disks not selected are untouched; native `file_*` calls are not covered.

## AiLens

Section `ai`, only when `laravel/ai` is installed. One row per agent invocation: `agent`, `provider`, `model`, `prompt`, `response` (or `structured`), `streamed`, `steps`, `tool_calls`, `failed_tools`, `tokens` (input, output, cache_read, cache_write, reasoning), `status` (`ok`/`failed`), `exception` and `duration`.

The timeline carries the tree: an `ai.agent` span per invocation, `ai.step` children per provider round-trip (model, finish reason, tokens, provider time) and `ai.tool` children per tool call (arguments, result, tool time). An agent invoked from inside a tool nests under that tool's span. Prompts and responses are kept whole; set the lens option `max_text` to truncate them.

The summary adds total tokens, calls per provider/model and warnings for failed agents and tools.
