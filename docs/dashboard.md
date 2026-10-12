<!-- Part of the Monocle documentation - see README.md -->

# Dashboard

Open `/_monocle` in your browser to see recent captures: what took the time, the timeline of every span, queries, cache calls and the other sections, and the jobs a request dispatched.

The list shows how many warnings each capture has and marks the ones with errors in red (5xx responses, failed jobs and commands, exceptions and errors a lens reported). Filter it by type, search it the way the command palette searches (below), or show only captures with errors. **hide route** on a capture keeps that route (or command) out of the list until you show it again; this is remembered in your browser.

Use the keyboard to move around: **↑** / **↓** (or **k** / **j**) open the previous or next capture on the same tab, **Home** opens the newest, and **←** / **→** (or **u** / **i**) open the previous or next tab. Shortcuts are ignored while typing in a field. **Ctrl K** (**⌘ K** on a Mac) opens a search in groups: quick actions (the latest capture of each type, such as **Latest request**, and clearing the list, which asks first), the open capture's tabs, then requests, commands, jobs and MCP calls. Type a few letters of the name or route name in order, also of a status code or kind (`404`, `job`); several words must all match, so `404 demo` finds the 404s under demo. Start a word with `#` to match the kind only (`#req`, `#job`), with `@` to match the route name only (`@users.index`) or with `$` to match a section the capture has, by its key or tab name (`$live` finds captures with Livewire components). `|` separates alternatives: `#cmd | 404` finds commands and 404s. Pick with the arrow keys and **Enter**.

## Warnings and errors

Lenses report two kinds of problems:

- **Errors** are things that failed: exceptions, failed HTTP calls, jobs, scheduled tasks and processes, error log entries, failed AI, Prism, MCP and filesystem calls, Filament actions that failed, Livewire components that threw, and 5xx responses.
- **Warnings** are things worth a look: slow, duplicate and N+1 queries, slow cache calls and keys read over and over, views rendered many times, slow middleware, and the like. Monocle adds a warning when it failed to record part of a capture, or when a span was never stopped.

The overview lists them under **Warnings / Errors**, errors first, with the count of each next to the title. The first three are shown; **Show more** lists the rest.

A tab's count turns red when its section reported errors and yellow when it reported only warnings. A red count with a yellow dot means both. A tab without a count shows a dot in the same colours. Hover the count or dot to see how many warnings and errors.

## What Monocle warns about

Every warning and error a capture can carry, by lens. Options are set per lens under `lenses` in `config/monocle.php`, for example `DatabaseLens::class => ['slow_ms' => 100]`. Each one has a demo under `/demo/alarms` (or an older demo) in the demo application.

Slow and duplicate queries, repeated cache reads and slow middleware are only flagged when application code caused them: a query or cache read that came from package code alone, with no application code on the way, and middleware shipped by a package are left out, since they are not yours to fix. Views from `vendor/` are left out of the repeated view warning the same way.

| Alarm | Level | What it means | Tune with |
| --- | --- | --- | --- |
| Slow query | warning | A query took at least `slow_ms`. The three slowest are listed. | `DatabaseLens` `slow_ms` (70) |
| Duplicate queries | warning | The same SQL with the same bindings ran more than once. | - |
| Possible N+1 | warning | The same select shape ran at least `n_plus_one_threshold` times, usually a relation loaded in a loop. | `DatabaseLens` `n_plus_one_threshold` (3) |
| Slow cache call | warning | One cache call took at least `slow_ms`, wherever it came from. | `CacheLens` `slow_ms` (20) |
| Cache key read repeatedly | warning | One key was read at least `repeat_threshold` times, `repeat_ms` in all; remember it for the request with `Cache::memo()` or `once()`. | `CacheLens` `repeat_threshold` (5), `repeat_ms` (0) |
| View rendered repeatedly | warning | One view was rendered at least `repeat_threshold` times and took at least `repeat_ms` in all. | `ViewsLens` `repeat_threshold` (10), `repeat_ms` (20) |
| Slow middleware | warning | A middleware spent at least `slow_ms` of its own time, before and after `$next`. | `MiddlewareLens` `slow_ms` (50) |
| Middleware short-circuited | warning | A middleware answered itself (a redirect, a 403, a cached response) and the request never reached the route. | - |
| Authorization denied | warning | A gate or policy check said no. The first three are listed. | - |
| Login locked out | warning | A `Lockout` event fired: too many failed logins. | - |
| Impersonated by | tag | The request ran while one user impersonated another (lab404/laravel-impersonate, stechstudio/filament-impersonate, Nova, Mirror). Shown in yellow in the header and the list. | - |
| dd() ended the process | warning | `dd()` stopped the request before a response was sent. Spans still open then are reported as never stopped too. | - |
| Slow process | warning | A process took at least `slow_ms`. | `ProcessLens` `slow_ms` (1000) |
| Process failed | error | A process exited with a non-zero code. | - |
| Process timed out | error | A process was stopped by its timeout. | - |
| HTTP call failed | error | An outgoing call got a 4xx or 5xx, or no response at all (DNS, connection, TLS). | - |
| Exception | error | An exception was reported or thrown. The first three are listed. | - |
| Logged error | error | A log entry at `error`, `critical`, `alert` or `emergency` level. | - |
| Response status 5xx | error | The request ended with a server error status. | - |
| Job failed | error | A queued job threw; shown on the job's own capture. | - |
| Command failed | error | An Artisan command run from code exited with a non-zero code. | - |
| Scheduled task failed | error | A task run by `schedule:run` failed; shown on that command's capture. | - |
| Filesystem operation failed | error | A Storage call failed, such as reading a file that does not exist. | `FilesystemLens` `disks` (which disks are watched) |
| Livewire component threw | error | A Livewire component threw while updating, calling a method or rendering, even when it handled the exception. | - |
| Filament action failed | error | A Filament action called `failure()`. | - |
| Filament action threw | error | A Filament action threw an exception. | - |
| Filament table without pagination | warning | A Filament table without pagination loaded more than `large_table_records` records. | `FilamentLens` `large_table_records` (500) |
| Large Inertia prop | warning | One prop was bigger than `large_prop_bytes` as JSON. | `InertiaLens` `large_prop_bytes` (50000) |
| Large Inertia page | warning | The whole page was bigger than `large_page_bytes`. | `InertiaLens` `large_page_bytes` (200000) |
| Shared prop on a partial reload | warning | A shared prop was sent on a partial reload that did not ask for it; make it lazy or `Inertia::always()` on purpose. | - |
| Undefined feature | warning | A Pennant feature was checked that is not defined; it resolved to false. | - |
| Served from the response cache | warning | spatie/laravel-responsecache answered and the controller did not run. | - |
| Media conversion during the request | warning | spatie/laravel-medialibrary ran conversions while the request waited; queue them to keep the response fast. | - |
| Agent failed | error | A laravel/ai agent call failed. | - |
| Tool failed | error | A tool a laravel/ai agent called threw. | - |
| Prism call failed | error | A Prism call failed. | - |
| Prism tool failed | error | A tool Prism ran threw. | - |
| MCP call returned an error | error | A call to one of your MCP servers answered with an error. | - |
| Monocle failed to record | warning | A lens threw while recording, for example while reading an event payload; the rest of the capture is complete and the app carried on. | - |
| Spans never stopped | warning | A span (yours via `Monocle::span()`, or one cut short by `dd()` or `exit`) was still running when the capture ended and was closed then. | - |

Some rows are marked in their tab without raising a warning: rolled back writes (`rolled_back` in the models tab), failed validation, events nobody listens to, and failed 2FA challenges with Laravel Fortify.

## The overview

The overview shows where the time went (each phase of the request), what it was spent on (queries, cache, HTTP calls and so on), the warnings and errors, and the totals. The duration, peak memory, response size, middleware time, middleware count and the time Monocle itself took (and its share of the duration) are at the top; the other totals are grouped by the section that reported them. Click a group's name to open its tab. **Totals** folds the grouped totals away; the ones at the top stay.

For a GET request, the capture's title opens the URL in a new window, so you can run the request again.

**Copy agent prompt** in the header copies a prompt for your coding agent: the capture, its totals, what Monocle flagged and how to dig in through the MCP server. Paste it into the agent to have it look into that request.

A capture made while one user impersonates another is tagged "impersonated by ..." in yellow, in the header and in the list.

## Tabs

Every section with rows gets a tab, and so does a section of facts (such as the request or the session) whose lens has a view for it. Tables list rows in order or grouped: queries by query, caller, table or transaction, HTTP calls by endpoint, host, pool or status, middleware by group, and views, cache, events, files, jobs, mail, authorization, exceptions, feature flags, Redis and log entries by what they have in common, such as the view, the cache key or the event. The log can also be filtered by level. Each group shows its count and timing and expands to the calls in it, and a query expands to its bindings and details in both views.

Queries that look like an N+1 are marked with how often they ran, duplicates point at the first query like them, and slow queries are marked as slow. Model changes are listed in the Models tab under the counts, login and other auth events in the Request tab, and commands a request or job ran with `Artisan::call()` in a Commands tab.

Each tab starts with the totals of its section, such as queries by kind or exceptions by source. The tab bar stays at the top while you scroll, and with a mouse you can drag it sideways when there are more tabs than fit. Class names, paths and values have a copy button; paths are copied relative to your project.

The tabs follow `dashboard.tabs` in the configuration; see below.

Click a line on the timeline or a row in a table to see its details: the query with its values, every field in full with a button to copy it, the line of your code that caused it and the call chain leading there. With an editor set up (see [Installation](installation.md#opening-files-in-your-editor)), file paths and class names open in your editor.

## Jobs and traces

A capture with a parent or children has a **Trace** tab (always last): the capture that started it all with every capture started from it, nested, each with its status, duration and when it started. Children are the queued jobs a capture dispatched, and, when turned on, the calls a page made and the commands a capture ran through the `Process` facade; see [Tracing](tracing.md). Each capture in the tree has a chip saying how it was linked: *queue*, *browser*, *navigation* or *process*. The header says **Started by parent capture via ...**, linking back, and **Started N child capture(s)** opens the Trace tab. A request that was asked to link to a page this store does not hold says *Asked to link to `<id>`, not found* instead. In the Jobs tab, the `run` column links each queued job to its own capture, or shows *pending* until a worker has run it.

## Access

By default the dashboard is only available in the `local` environment. Define the `viewMonocle` gate in a service provider to decide yourself:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewMonocle', fn ($user = null) => $user?->isAdmin() ?? false);
```

## Configuration

```php
'dashboard' => [
    'enabled' => env('MONOCLE_DASHBOARD', true),
    'path' => env('MONOCLE_PATH', '_monocle'),
    'domain' => env('MONOCLE_DOMAIN'),
    'middleware' => ['web'],
    'tabs' => [
        RequestLens::class,
        SessionLens::class,
        DatabaseLens::class,
        ExceptionsLens::class,
        // ...
    ],
],
```

| Key | Meaning |
|---|---|
| `enabled` | `false` removes the dashboard and its API |
| `path` | where the dashboard lives; the API is under `{path}/api` |
| `domain` | serve the dashboard on this domain only |
| `middleware` | middleware for the dashboard and its API |
| `tabs` | the order of the tabs after Overview and Timeline: lens classes or section keys. Tabs not listed, such as those of your own lenses, follow in the order their sections were recorded |

Requests to the dashboard are not recorded.

## Demo application

The repository ships a small demo application for trying Monocle without an app of your own. From a clone of the repository, run:

```bash
composer serve
```

or, with Docker:

```bash
docker compose up serve
```

Then open `http://localhost:8000`. The start page lists every demo, one or more per lens, with a short description. **Publish a post** at the top runs most lenses in a single request, for a full timeline. Trigger one and open the dashboard at `/_monocle` to inspect the capture it recorded. The database is rebuilt and seeded each time the server starts; the seeded users share the password `password`.
