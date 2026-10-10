<!-- Part of the Monocle documentation - see README.md -->

# For AI agents

Monocle exists so that a coding agent can answer "what did the application actually do when I hit that endpoint?" without reading logs or guessing. Everything is exposed as a handful of MCP tools that return compact JSON with pagination, filtering and field projection built in, so the agent fetches only what it needs.

## The workflow

1. Reproduce the behaviour: open the page, `curl` the endpoint, run the job or the command.
2. `monocle-list` to find the capture, or read the `X-Monocle-Id` header of the response you just got.
3. `monocle-show id=...` for the summary: where the time went, totals and warnings. The response also lists every available section with a description of what it holds.
4. Dig in with `monocle-rows` (any section, filtered and paginated), `monocle-queries` (grouped queries) or `monocle-show sections=[timeline]`.
5. Every row carries `origin` - the user-land `file:line` that triggered it - so the agent opens the code, changes it, reproduces again and compares.

The server's own instructions tell the agent this and list every enabled lens with its section key and a description of what it records, so the agent knows which sections exist before its first call. `monocle-show` repeats the list as `available_sections` with row counts for the capture at hand, and `monocle-list` rows carry `counts` per section.

## Tools

All tools return a single JSON block. Lists always carry `total`, `returned`, `offset` and `next_offset` (null when there is no more), so the agent knows whether it has seen everything.

### `monocle-list`

Recent captures, newest first.

| Argument | Meaning |
|---|---|
| `type` | `request`, `job`, `command` or `mcp` |
| `uri` | substring of the capture name, e.g. `/api/orders` or `POST` |
| `status` | exact HTTP status |
| `min_duration_ms` | only captures at least this slow |
| `since` | unix timestamp, ISO date or relative like `15m`, `2h` |
| `label` | captures labelled via `X-Monocle-Label` or `Monocle::label()` |
| `has_errors` | only 5xx, failures or recorded exceptions |
| `has` | only captures where these sections recorded rows, e.g. `["exceptions"]` or `["ai"]` |
| `offset`, `limit` | pagination (default 20) |

Each row: `id`, `type`, `name`, `status`, `duration`, `started_at`, `label`, `parent_id`, `trace_id`, `counts` per section, `has_errors`.

### `monocle-show`

One capture. `id` defaults to `latest`.

`sections` defaults to `["summary"]`. The summary contains:

- `breakdown.phases` - exclusive wall-clock slices: `bootstrap`, `middleware` (own time of all middleware), `routing`, `controller`, `terminate`, and `other` for whatever is unaccounted
- `breakdown.categories` - overlapping categories measured from recorded rows and spans: `db`, `cache`, `redis`, `http`, `fs`, `mail`, `views`, `ai`, `jobs_sync`
- `totals` - contributed by every lens (query count and ms, cache hit rate, memory peak, response size, ...)
- `warnings` - slow queries, duplicates, N+1 candidates, slow or short-circuiting middleware, 5xx, spans never stopped
- `parent_id`, `trace_id`, `children` for jumping along a trace
- `health` - Monocle's own overhead in ms and the sections present
- `available_sections` - every section the capture has or a booted lens could have written, each with a description and row count

Add `"timeline"` for the span tree (filter with `kinds`, e.g. `["middleware"]`) or any section name for its first rows (`limit`, default 25). Pass `verbose=true` to include full origin call chains.

### `monocle-rows`

Paginated rows of one section: `section` (required), `id`, `offset`, `limit` (max 500), `sort`, `dir`, `where`, `fields`, `verbose`.

```json
{
  "section": "queries",
  "where": [{"field": "duration", "op": ">", "value": "20"}],
  "sort": "duration", "dir": "desc",
  "fields": ["sql_with_bindings", "duration", "origin"]
}
```

`where` operators: `=`, `!=`, `>`, `<`, `>=`, `<=`, `contains`, `starts_with`, `in`, `null`, `not_null`. Fields use dot notation (`origin.file`). `section=timeline` works too.

### `monocle-queries`

The queries section grouped by `shape` (SQL with literals and IN-lists normalized), `caller` (origin file:line), `table` or `transaction`. Each group has `count`, `total_ms`, `avg_ms`, `max_ms`, an `example` and the first `origin`. Sort by `total_ms`, `count`, `max_ms` or `avg_ms`.

### `monocle-trace`

Every capture sharing the trace of the given one - the request or command that started it and the jobs it dispatched - with `depth` and `offset_ms` from the root.

### `monocle-compare`

`a` (id or label) against `b` (id, label or `latest`): every numeric total with `a`, `b`, `delta` and `percent`; time per phase and category side by side; query shapes only in a, only in b, or with a changed count; warnings unique to each side. The workflow it serves: label a run (`X-Monocle-Label: before` or `Monocle::label('before')`), change the code, reproduce, `monocle-compare a=before`.

### `monocle-clear`

Empties the store. Useful right before reproducing so `monocle-list` only shows what happens next.

## Response headers

Every recorded response carries:

```http
X-Monocle-Id: 01a126fa-6f82-71f5-8387-75067ec20c61
Server-Timing: app;dur=467.5, bootstrap;dur=51.9, middleware;dur=2.6, routing;dur=0.9, handle;dur=412.1, db;dur=16.8
```

And two request headers steer recording:

- `X-Monocle-Label: baseline` labels the capture so it can be found again with `monocle-list label=baseline`.

## `origin`

Every row and every span records where in your code it came from: the first stack frame outside `vendor/` and outside Monocle itself, as `file` (relative to the project), `line`, `call`, plus up to five more frames of the call chain. Compiled Blade views are mapped back to their `.blade.php` source. Tools compact it to `file:line` unless `verbose=true`.

Vendor paths you do want to see (your own packages) go in `monocle.origin.include_vendor`.
