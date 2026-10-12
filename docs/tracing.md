<!-- Part of the Monocle documentation - see README.md -->

# Tracing

One click in the browser can set off a lot of work: the page loads, it calls the server a few times, those calls dispatch jobs, a job runs a command in a separate process. Monocle records each of these as a capture of its own and links them into a **trace**: a tree with the first capture at the root and every capture started from it below, each pointing back at its parent.

## What gets linked

### Queued jobs

Always on. A queued job becomes its own capture when a worker runs it, a child of the request, command or job that dispatched it. Jobs that job dispatches, and the next jobs of a chain, are linked to it in turn. See [Queue](lenses/queue.md#how-jobs-are-linked).

### Calls a page makes

Off by default. With [`trace.browser`](configuration.md#trace) on, recorded HTML pages get a small script that links the `fetch()` and `XMLHttpRequest` calls they make to the page's capture, follows SPA navigation, and adds a Monocle button that opens the page's captures in the dashboard. See [Browser tracing](browser-tracing.md).

### Commands run in a process

On by default (`trace.processes`). A process started through the `Process` facade while a capture is being recorded gets two environment variables, `MONOCLE_TRACE_ID` and `MONOCLE_PARENT_ID`. When that process runs an artisan command of the same application (`Process::run('php artisan orders:sync')`), the command capture becomes a child of the capture that started the process. Variables you pass yourself with `Process::env()` win over these.

## How a link was made

Every child capture says how it was linked to its parent, as `linked_via`:

| `linked_via` | The child is |
|---|---|
| `queue` | a queued job, run by a worker, that its parent dispatched |
| `browser` | a `fetch()` or `XMLHttpRequest` call made by the page that is its parent |
| `navigation` | a call made by the page that answered with a whole page (an HTML document with `</body>`, or an Inertia page): an SPA router fetching the next page |
| `process` | an artisan command run in a process its parent started through the `Process` facade |

Captures recorded before this was added, and root captures, have no `linked_via`.

## Seeing a trace

- **Trace tab.** A capture with a parent or children has a **Trace** tab (always last): the whole tree, each capture with its type, how it was linked (a small chip such as *queue* or *browser*), name, status, duration and when it started after the root. Click one to open it. See [Dashboard](dashboard.md#jobs-and-traces).
- **The header.** A child capture says **Started by parent capture via browser** (or via queue, navigation, process), linking back. A capture with children says **Started N child capture(s)**, which opens the Trace tab.
- **For agents.** `monocle-trace` returns every capture in the trace with its `parent_id`, `depth` and start offset, plus queued jobs no worker has run yet. Each child carries its `linked_via`. `monocle-trace` with:

```json
{"id": "latest"}
```

`id` can be any capture in the trace. `monocle:trace` shows the same tree in the terminal. See [For AI agents](agents.md#monocle-trace).

## Configuration

```php
'trace' => [
    'browser' => false,
    'processes' => true,
],
```

| Key | Default | What it does |
|---|---|---|
| `browser` | `false` | Put the trace script into recorded HTML pages, and link requests that carry `X-Monocle-Parent` to the page that sent them. Both need this on: with it off, the header is ignored. |
| `processes` | `true` | Hand the trace to processes started through the `Process` facade, and let a command capture join the trace it was handed. |

See [Configuration](configuration.md#trace).

## What is not linked

- **Browser calls** that are not linked (full page loads, other origins, calls without `fetch` or `XMLHttpRequest`, pages whose CSP blocks the script): see [Browser tracing](browser-tracing.md#what-is-not-linked).
- **Parents this store does not hold.** Linking needs the parent in the same Monocle store. A page captured by another application with its own store, a parent pushed out of the ring buffer (`storage.limit`), or a call the page made so early that it arrived before the page's capture was stored, give the *not found* note instead.
- **Requests and commands that are not recorded** (`except.paths`, `except.commands`, the dashboard). They pass nothing on, and the pages among them get no script.
- **`Artisan::call()`** runs in the same process. The command is a span in the capture that called it, not a child capture.
- **Processes started without the `Process` facade** (`exec()`, `shell_exec()`, Symfony's `Process` directly), and processes that are not an artisan command of an application with Monocle enabled.
- **Scheduled tasks.** `schedule:run` starts command tasks in separate processes without the `Process` facade, so their captures are not linked to it. A task run in the background reports its end through `schedule:finish`, which is a capture of its own. See [Scheduler](lenses/scheduler.md#how-scheduled-tasks-are-captured).
- **Queue workers.** `queue:work`, `queue:listen` and Horizon are not recorded; the jobs they run are, each linked to the capture that dispatched it.
