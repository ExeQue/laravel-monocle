<!-- Part of the Monocle documentation - see README.md -->

# For AI agents

Monocle lets a coding agent answer "what did the application do when I hit that endpoint?" without reading logs or guessing.

## The workflow

1. Reproduce: open the page, `curl` the endpoint, run the job or the command.
2. Find the capture with `monocle-list`, or take the id from the `X-Monocle-Id` response header.
3. `monocle-show` shows where the time went, the totals and any warnings.
4. Dig into a section with `monocle-rows` or `monocle-queries`.
5. Every row has an `origin` - the `file:line` in your code that caused it. Fix the code, reproduce, and use `monocle-compare` to see the difference.

The agent learns which sections exist (queries, cache, views, ...) and what they contain as soon as it connects, so it can go straight to the right one.

A **capture** is one request, queued job, artisan command or MCP call.

## Tools

Lists always tell the agent how many results there are in total and where the next page starts.

### `monocle-list`

Recent captures, newest first.

| Argument | Meaning |
|---|---|
| `type` | `request`, `job`, `command` or `mcp` |
| `uri` | part of the name, e.g. `/api/orders` or `POST` |
| `status` | HTTP status |
| `min_duration_ms` | only captures at least this slow |
| `since` | a timestamp, a date, or `15m`, `2h`, ... |
| `label` | captures with this label |
| `has_errors` | only captures with a 5xx status, a failure or an exception |
| `has` | only captures with rows in these sections, e.g. `["exceptions"]` |
| `offset`, `limit` | paging |

### `monocle-show`

One capture - the latest unless an `id` is given. By default it returns the summary:

- **Time breakdown** - bootstrap, middleware, routing, controller and terminate, plus how much of it was database, cache, views, HTTP calls, filesystem, AI and so on.
- **Totals** - number of queries, cache hit rate, memory, tokens, ...
- **Warnings** - slow queries, N+1 candidates, duplicate queries, slow middleware, errors and failures.
- **Trace links** - the parent capture and any jobs it dispatched.
- **Available sections** - what else can be fetched, with a description and row count.

Ask for more with `sections`, e.g. `["timeline", "queries"]`. The timeline is the tree of timed spans; filter it with `kinds`, e.g. `["middleware"]`.

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

Operators: `=`, `!=`, `>`, `<`, `>=`, `<=`, `contains`, `starts_with`, `in`, `null`, `not_null`. Use dots for nested fields, e.g. `origin.file`. Add `verbose: true` to see the full call chain behind each `origin`.

### `monocle-queries`

Database queries grouped by `shape` (the same query with different values), `caller` (the line of code), `table` or `transaction`, with count and total, average and max time.

### `monocle-trace`

A request or command together with every job it dispatched, and the jobs those dispatched.

### `monocle-compare`

Compares two captures: every total side by side, time per phase, queries that appeared or disappeared, and warnings only one side has.

Label a run, change the code, run it again and compare:

```
curl -H "X-Monocle-Label: before" https://app.test/orders
# ... change the code ...
curl https://app.test/orders
monocle-compare a=before
```

### `monocle-clear`

Deletes all captures, so the next `monocle-list` only shows what happens after.

## Headers

Each recorded response has:

```http
X-Monocle-Id: 01a126fa-6f82-71f5-8387-75067ec20c61
Server-Timing: app;dur=467.5, bootstrap;dur=51.9, middleware;dur=2.6, routing;dur=0.9, handle;dur=412.1, db;dur=16.8
```

Send `X-Monocle-Label: <name>` with a request to label its capture. Find it again with `monocle-list label=<name>` or compare against it with `monocle-compare`.
