<!-- Part of the Monocle documentation - see README.md -->

# Dashboard

Open `/_monocle` in your browser to see recent captures: what took the time, the timeline of every span, queries, cache calls and the other sections, and the jobs a request dispatched.

Captures Monocle flagged show how many warnings they have. Filter the list by type, path or name, or show only errors. **hide route** on a capture keeps that route (or command) out of the list until you show it again; this is remembered in your browser.

**Copy agent prompt** in the header copies a prompt for your coding agent: the capture, its totals, what Monocle flagged and how to dig in through the MCP server. Paste it into the agent to have it look into that request.

Click a line on the timeline to see its details: the query with its values, the line of your code that caused it and the call chain leading there. With an editor set up (see [Installation](installation.md#opening-files-in-your-editor)), file paths and class names open in your editor.

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
],
```

| Key | Meaning |
|---|---|
| `enabled` | `false` removes the dashboard and its API |
| `path` | where the dashboard lives; the API is under `{path}/api` |
| `domain` | serve the dashboard on this domain only |
| `middleware` | middleware for the dashboard and its API |

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

Then open `http://localhost:8000`. The start page lists every demo, one or more per lens, with a short description. Trigger one and open the dashboard at `/_monocle` to inspect the capture it recorded. The database is rebuilt and seeded each time the server starts; the seeded users share the password `password`.
