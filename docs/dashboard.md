<!-- Part of the Monocle documentation - see README.md -->

# Dashboard

Open `/_monocle` in your browser to see recent captures: what took the time, the timeline of every span, queries, cache calls and the other sections, and the jobs a request dispatched.

The list shows how many warnings each capture has and marks the ones with errors in red. Filter it by type, path or name, or show only captures with errors. **hide route** on a capture keeps that route (or command) out of the list until you show it again; this is remembered in your browser.

Use the keyboard to move around: **↑** / **↓** (or **k** / **j**) open the previous or next capture on the same tab, **Home** opens the newest, and **←** / **→** (or **u** / **i**) open the previous or next tab.

## Warnings and errors

Lenses report two kinds of problems:

- **Errors** are things that failed: exceptions, failed HTTP calls, jobs and scheduled tasks, error log entries, failed AI, MCP and filesystem calls, and 5xx responses.
- **Warnings** are things worth a look: slow, duplicate and N+1 queries, slow cache calls and keys read over and over, views rendered many times, slow middleware, and the like.

The overview lists them under **Warnings / Errors**, errors first, with the count of each next to the title. The first three are shown; **Show more** lists the rest.

A tab's count turns red when its section reported errors and yellow when it reported only warnings. A red count with a yellow dot means both. A tab without a count shows a dot in the same colours.

## The overview

The overview shows where the time went (each phase of the request), what it was spent on (queries, cache, HTTP calls and so on), the warnings and errors, and the totals. The duration, peak memory, response size and middleware time are at the top; the other totals are grouped by the section that reported them. Click a group's name to open its tab. **Totals** folds the groups away.

**Copy agent prompt** in the header copies a prompt for your coding agent: the capture, its totals, what Monocle flagged and how to dig in through the MCP server. Paste it into the agent to have it look into that request.

A capture made while one user impersonates another is tagged "impersonated by ..." in yellow, in the header and in the list.

## Tabs

Every section with rows gets a tab, and so does a section of facts (such as the request or the session) whose lens has a view for it. Tables list rows in order or grouped: queries by query, caller, table or transaction, HTTP calls by endpoint, host, pool or status, middleware by group, and views, cache, events, files, jobs, mail, authorization, exceptions, feature flags, Redis and log entries by what they have in common, such as the view, the cache key or the event. The log can also be filtered by level. Each group shows its count and timing and expands to the calls in it, and a query expands to its bindings and details in both views.

Queries that look like an N+1 are marked with how often they ran, duplicates point at the first query like them, and slow queries are marked as slow. Model changes are listed in the Models tab under the counts, and login and other auth events in the Request tab.

The tabs follow `dashboard.tabs` in the configuration; see below.

Click a line on the timeline or a row in a table to see its details: the query with its values, every field in full with a button to copy it, the line of your code that caused it and the call chain leading there. With an editor set up (see [Installation](installation.md#opening-files-in-your-editor)), file paths and class names open in your editor.

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
