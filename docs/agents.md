<!-- Part of the Monocle documentation - see README.md -->

# For AI agents

Monocle lets a coding agent answer "what did the application do when I hit that endpoint?" without reading logs or guessing.

## The workflow

1. Reproduce: open the page, `curl` the endpoint, run the job or the command.
2. Find the capture with `monocle-list`, or take the id from the `X-Monocle-Id` response header.
3. `monocle-show` shows where the time went, the totals and any errors and warnings.
4. Dig into a section with `monocle-rows` or `monocle-queries`.
5. Every row has an `origin` - the `file:line` in your code that caused it, and `via` - the library call it went through on the way, when there is one. Fix the code, reproduce, and use `monocle-compare` to see the difference.

The agent learns which sections exist (queries, cache, views, ...) and when to look at each as soon as it connects, so it can go straight to the right one.

A **capture** is one request, queued job, artisan command or MCP call.

## Tools

Lists always tell the agent how many results there are in total and where the next page starts.

### `monocle-list`

Recent captures, newest first.

| Argument | Meaning |
|---|---|
| `type` | `request`, `job`, `command` or `mcp` |
| `search` | free text; every word must appear in the name, route, type, status, label or a tag |
| `uri` | part of the name, e.g. `/api/orders` or `POST` |
| `status` | HTTP status |
| `min_duration_ms` | only captures at least this slow |
| `since` | a timestamp, a date, or `15m`, `2h`, ... |
| `label` | captures with this label |
| `has_errors` | only captures with errors: a 5xx status, a failed job or command, an exception, or an error a lens reported (a failed HTTP call, an error log entry, ...) |
| `has` | only captures with rows in these sections, e.g. `["exceptions"]` |
| `exclude` | leave out captures whose name contains any of these, or whose route name is one of them, e.g. `["/livewire"]` or `["livewire.update"]` |
| `offset`, `limit` | paging |

### `monocle-show`

One capture - the latest unless an `id` is given. By default it returns the summary:

- **Time breakdown** - bootstrap, middleware (request), routing, controller, middleware (response) and terminate, plus how much of it was database, cache, views, HTTP calls, filesystem, AI and so on.
- **Totals** - number of queries, cache hit rate, memory, tokens, ... Names end in their unit where they have one (`_ms`, `_mb`, `_bytes`, `_percent`).
- **Flags** - errors (exceptions, failed HTTP calls, jobs and scheduled tasks, error log entries, 5xx responses, ...) and warnings (slow queries, N+1 candidates, duplicate queries, slow middleware, ...), errors first, each with its level and the section that reported it, so the agent knows where to dig.
- **Trace links** - the parent capture and its child captures (jobs, calls a page made, commands run in a process). See [Tracing](tracing.md).
- **Available sections** - each section holding data, with its row count (`"record"` for a section holding one record, such as `request`). What each section records is in the server instructions and `monocle-lenses`.

To save tokens, keys without a value (null or empty) are left out. The summary has these keys:

| Key | Holds |
|---|---|
| `id`, `type`, `name`, `status`, `label`, `started_at`, `ended_by` | the capture |
| `parent_id`, `trace_id`, `children` | trace links |
| `linked_via` | how the capture was linked to its parent: `queue`, `browser`, `navigation` or `process`; left out for a root |
| `missing_parent_id` | a parent id a request was sent with that this store does not hold (the capture is not linked) |
| `breakdown` | time per phase and per category |
| `totals` | name => value |
| `headlines` | the totals about the whole capture, such as duration and peak memory |
| `flags` | every error and warning as `{level, section, message}`, errors first |
| `tags` | short labels for the whole capture as `{label, tone}`, such as "impersonated by #1 (web guard)" |
| `meta` | metadata added to the capture |
| `monocle_top` | the five lenses that took Monocle the most time (registering, listeners and the rows they wrote; application code a lens calls is left out). The total is the `monocle_ms` and `monocle_percent` totals |
| `health` | `finalize_ms` (time spent closing the capture, outside the request), and lens failures and spans left open, when there were any |

The dashboard and the tables of `monocle:show` get the full summary, with the labels, units and groups they lay it out by.

Ask for more with `sections`, e.g. `["timeline", "queries"]`. The timeline is the tree of timed spans; filter it with `kinds`, e.g. `["middleware"]` (which includes `middleware.request` and `middleware.response`). `limit` sets the rows per section (default 25); `verbose: true` adds the full call chain behind each `origin`.

### `monocle-rows`

Rows of one section, with filtering, sorting, paging and a choice of fields:

```json
{
  "section": "queries",
  "where": [{"field": "duration", "op": ">", "value": "20"}],
  "sort": "duration", "dir": "desc",
  "fields": ["sql_with_bindings", "duration", "origin"]
}
```

Operators: `=`, `!=`, `>`, `<`, `>=`, `<=`, `contains`, `starts_with`, `in`, `null`, `not_null`. Use dots for nested fields, e.g. `origin.file`. Add `verbose: true` to see the full call chain behind each `origin`. For `section: "timeline"`, `kinds` limits the spans as in `monocle-show`. `search` keeps only rows where every word appears in some value, nested values included.

### `monocle-queries`

Database queries grouped by `shape` (the same query with different values), `caller` (the line of code), `table` or `transaction`, with count and total, average and max time. Sort groups with `sort` (`total_ms` by default, `count`, `max_ms` or `avg_ms`); page with `offset` and `limit`.

### `monocle-trace`

Every capture in the same trace as the given one, oldest first, each with its `parent_id`, `linked_via` (`queue`, `browser`, `navigation` or `process`), `depth` and start offset: the request or command that started it, the jobs it dispatched and the jobs those dispatched, and, when turned on, the calls a page made and commands run through the `Process` facade. `pending` lists queued jobs no worker has run yet, each with the capture that dispatched it (`parent_id`), the job class and its job id. See [Tracing](tracing.md).

```json
{"id": "latest"}
```

### `monocle-compare`

Compares two captures: every total side by side, time per phase, queries that appeared or disappeared, and errors and warnings only one side has.

Label a run, change the code, run it again and compare:

```
curl -H "X-Monocle-Label: before" https://app.test/orders
# ... change the code ...
curl https://app.test/orders
monocle-compare a=before
```

### `monocle-clear`

Deletes all captures, so the next `monocle-list` only shows what happens after.

### `monocle-lenses`

Lists the lenses enabled in the application: each section key you can pass to `monocle-show` and `monocle-rows`, with when to look at it and the fields it records. Pass `sections`, e.g. `["queries"]`, to describe only those. The server instructions carry only the when; this is where the fields are.

## Skill

Monocle ships a skill that teaches your agent the workflow above and lists the sections your application records. With [Laravel Boost](https://laravel.com/docs/boost), `boost:install` and `boost:update` add it for every agent Boost is set up for. Without Boost, `monocle:install` offers to write it for the agents your project uses.

## Headers

Each recorded response has:

```http
X-Monocle-Id: 01a126fa-6f82-71f5-8387-75067ec20c61
Server-Timing: app;dur=467.5, bootstrap;dur=51.9, middleware-request;dur=2.6, routing;dur=0.9, handle;dur=412.1, db;dur=16.8
```

Send `X-Monocle-Label: <name>` with a request to label its capture. Find it again with `monocle-list label=<name>` or compare against it with `monocle-compare`.
