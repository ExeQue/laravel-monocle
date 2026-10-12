<!-- Part of the Monocle documentation - see README.md -->

# Tracing

One click in the browser can set off a lot of work: the page loads, it calls the server a few times, those calls dispatch jobs, a job runs a command in a separate process. Monocle records each of these as a capture of its own and links them into a **trace**: a tree with the first capture at the root and every capture started from it below, each pointing back at its parent.

## What gets linked

### Queued jobs

Always on. A queued job becomes its own capture when a worker runs it, a child of the request, command or job that dispatched it. Jobs that job dispatches, and the next jobs of a chain, are linked to it in turn. See [Queue](lenses/queue.md#how-jobs-are-linked).

### Calls a page makes

Off by default. Turn it on with [`trace.browser`](configuration.md#trace):

```php
'trace' => [
    'browser' => true,
],
```

With it on, every HTML page Monocle records gets a small inline script just before `</body>`. The script adds an `X-Monocle-Parent` header, holding the capture id of the page, to the `fetch()` and `XMLHttpRequest` calls the page makes to its own origin. A request carrying that header becomes a child of the page in the page's trace. Livewire updates, Inertia visits, your own `fetch()` calls and anything else that goes through `fetch` or `XMLHttpRequest` are linked this way. Calls to other origins are left alone, so the header never leaves your application.

The browser's console shows what the script does: a line when it starts linking the page, and a line for every call Monocle recorded, such as `POST /livewire/update recorded as ...8a3670d4, linked to page ...26c79c9a`, each with a link to the capture in the dashboard. Calls Monocle does not record (excepted paths, other origins) are not logged.

Pages that switch to the next page without a full reload (Livewire's `wire:navigate`, Inertia and other SPA routers that fetch the next page from the server) move the script along: once the address changes, the page fetched for that address becomes the current page, so later calls link to it. The trace then reads: the first page, its calls, the next page, its calls, and so on.

The header is only trusted when it holds a capture id, and only linked when the capture it names is in this application's Monocle store. When it is not there, the request is recorded as a capture of its own, with a note in its header: *Asked to link to `<id>`, not found*. Agents see the same id as `missing_parent_id` in the summary.

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

- **Full page loads.** Following a link, submitting a form or reloading the page is a new page in the browser, not a call made by the page. It starts a trace of its own.
- **Calls to other origins**, and calls made without `fetch` or `XMLHttpRequest` (images, stylesheets, `<script src>`, `navigator.sendBeacon()`, WebSockets).
- **Client-side route changes** that fetch nothing from the server keep linking to the last page that was fetched.
- **Pages with a Content Security Policy that forbids inline scripts.** The browser blocks the script, nothing is linked and the page works as before. When your application sets a nonce through Laravel's `Vite::useCspNonce()`, the script carries it and runs.
- **Parents this store does not hold.** Linking needs the parent in the same Monocle store. A page captured by another application with its own store, a parent pushed out of the ring buffer (`storage.limit`), or a call the page made so early that it arrived before the page's capture was stored, give the *not found* note instead.
- **Requests and commands that are not recorded** (`except.paths`, `except.commands`, the dashboard). They pass nothing on, and the pages among them get no script.
- **`Artisan::call()`** runs in the same process. The command is a span in the capture that called it, not a child capture.
- **Processes started without the `Process` facade** (`exec()`, `shell_exec()`, Symfony's `Process` directly), and processes that are not an artisan command of an application with Monocle enabled.
- **Scheduled tasks.** `schedule:run` starts command tasks in separate processes without the `Process` facade, so their captures are not linked to it. A task run in the background reports its end through `schedule:finish`, which is a capture of its own. See [Scheduler](lenses/scheduler.md#how-scheduled-tasks-are-captured).
- **Queue workers.** `queue:work`, `queue:listen` and Horizon are not recorded; the jobs they run are, each linked to the capture that dispatched it.
