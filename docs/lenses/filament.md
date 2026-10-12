<!-- Part of the Monocle documentation - see README.md -->

# Filament

The Filament lens records what Filament did during a request: the actions mounted or run and how they ended, the notifications sent, the state of every table rendered and the records created or updated by resource pages, one row each in section `filament`. It also records the panel, tenant, page and resource the request was for in section `filament_context`, and tells which Livewire components are Filament's on the [Livewire](livewire.md) rows.

## Rows

### Section `filament`

Every row has a `kind`: `action`, `notification`, `table` or `record`. All rows have `kind`, `name`, `component` and `time` (ms since the capture started).

**`action`** - an action mounted or run on a page, table, relation manager or other Filament component.

| Field | Meaning | Unit | Missing when |
|---|---|---|---|
| `name` | the action's name, such as `delete` | - | never |
| `component` | the Livewire component class it ran on | - | never |
| `status` | how it ended - see below | - | never |
| `record` | the key of the record the action was for | - | null when the action has no record |
| `table` | `true` for a table action | - | null otherwise |
| `bulk` | `true` for a bulk action | - | null otherwise |
| `arguments` | the arguments the action was mounted or called with | - | null when there were none |
| `errors` | the names of the form fields that failed validation | - | null unless the status is `invalid` |
| `exception` | the exception it threw: `class`, `message`, `file`, `previous` | - | null unless the status is `error` |
| `notifications` | the titles of the flash notifications it sent | - | null when it sent none |
| `duration` | how long the call took | ms | never |

The `status` of an action:

| Status | Meaning |
|---|---|
| `success` | the action ran and finished |
| `failed` | the action ran and ended with `failure()` |
| `halted` | the action started running but was halted or cancelled |
| `invalid` | its form failed validation; `errors` lists the fields |
| `error` | it threw an exception |
| `modal opened` | it was mounted and opened its modal, waiting for the user |
| `stopped` | it did not run, such as a disabled or unauthorized action |

**`notification`** - a notification sent with `Filament\Notifications\Notification`.

| Field | Meaning | Missing when |
|---|---|---|
| `name` | the notification's title | it has no title |
| `status` | its status, such as `success`, `warning`, `danger` or `info` | no status was set |
| `channel` | `flash` for `send()`, `database` for `sendToDatabase()`, `broadcast` for `broadcast()` | never |
| `body` | its body text | it has no body |
| `action` | the name of the action that sent it | not a flash notification, or not sent by an action |
| `notifiable` | who received it, as `Class#id` | a flash notification |

Database and broadcast notifications are queued, so their rows appear where they are delivered, such as the capture of the queued job.

**`table`** - a table rendered by a component, as it was last rendered in the request.

| Field | Meaning | Unit | Missing when |
|---|---|---|---|
| `name` | the table's model, such as `Book` | - | the table has no model |
| `component` | the component class holding the table | - | never |
| `filters` | the active filters and their values | - | null when no filter is set |
| `search` | the table search | - | empty when there is no search |
| `column_searches` | the per-column searches that are filled in | - | null when there are none |
| `sort` | the sort column and direction, such as `title desc` | - | null when not sorted |
| `grouping` | the active grouping | - | null when not grouped |
| `tab` | the active tab of a list page | - | null when the page has no tabs |
| `page` | the current page | - | null when the table is not paginated |
| `per_page` | the records per page | - | null when the table is not paginated |
| `records` | the number of records shown | count | null when no records were loaded |
| `total` | the total number of matching records | count | null unless the table uses length-aware pagination |
| `deferred` | `true` when loading was deferred and no records were loaded yet | - | null otherwise |
| `large` | `true` when the table is not paginated and loaded more than `large_table_records` records | - | null otherwise |

**`record`** - a record created or updated on a resource's create or edit page.

| Field | Meaning | Missing when |
|---|---|---|
| `name` | `created` or `updated` | never |
| `component` | the page class | the page is not known |
| `record` | the record as `Class#id` | never |

The data entered in an action's form or a resource form is not recorded.

### Section `filament_context`

One row, recorded when section `filament` has rows. Only the fields that are known are kept.

| Field | Meaning |
|---|---|
| `panel` | the panel id, such as `admin` |
| `path` | the panel path |
| `guard` | the panel's auth guard |
| `tenant` | the current tenant as `Class#id` |
| `page` | the class of the first Filament page in the request |
| `resource` | the page's resource class |
| `record` | the key of the record the page shows |

### Livewire rows

With the Livewire lens on, rows in section `livewire` for Filament components get these fields:

| Field | Meaning | Missing when |
|---|---|---|
| `filament` | what the component is to Filament: `resource page`, `page`, `relation manager`, `widget`, `table` or `component` | the component is not Filament's (then none of these fields are added) |
| `panel` | the panel id | no panel is active |
| `resource` | the resource class, for a resource page or a relation manager on one | other components |
| `record` | the key of the record the component shows | the component has no record |
| `owner_record` | the key of the owner record of a relation manager | other components |

## Totals, warnings and errors

When section `filament` has rows, the summary gets:

| Total | Label | Value |
|---|---|---|
| `filament_actions` | actions | the number of `action` rows |
| `filament_notifications` | notifications | the number of `notification` rows |

Errors:

- An action that threw an exception: "Filament action "`name`" on `Component` threw `ExceptionClass`: `message`".
- An action with status `failed`: "Filament action "`name`" on `Component` failed."

Warning: a table that is not paginated and loaded more than `large_table_records` records (500 by default): "Filament table on `Component` loaded `N` records without pagination."

An action that fails validation (`invalid`), is `halted` or `stopped` gives neither a warning nor an error.

## In the dashboard

The **Filament** tab shows the totals, then a line with the context - panel, path, guard, tenant, page, resource and record - then a table with one row per action, notification, table and record. The columns are kind, name, component, status, channel, record, search, filters, sort, records, total and duration, each shown only when a row has a value. Statuses `failed`, `error`, `invalid` and `danger` are shown in red; a row with an exception is tinted red, and a large table is tinted as a warning.

Group the rows **in order**, **by kind** or **by component**. Each row folds out to all of its fields. The context is also listed as its own small table below.

On the **Livewire** tab, the Filament fields appear on the rows of Filament components.

## For agents

What ran on the Filament page, and how did each action end?

```json
{
  "tool": "monocle-rows",
  "section": "filament",
  "where": [{ "field": "kind", "op": "=", "value": "action" }],
  "fields": ["name", "component", "status", "record", "errors", "exception", "notifications", "duration"]
}
```

Which panel, tenant, resource and record the request was for, next to the warnings and errors:

```json
{ "tool": "monocle-show", "id": "latest", "sections": ["summary", "filament_context"] }
```

The filters, search and sort of the tables on the page, largest first:

```json
{
  "tool": "monocle-rows",
  "section": "filament",
  "where": [{ "field": "kind", "op": "=", "value": "table" }],
  "sort": "records",
  "dir": "desc",
  "fields": ["name", "component", "filters", "search", "sort", "page", "per_page", "records", "total", "large"]
}
```

See [For AI agents](../agents.md#monocle-rows) for every filter `monocle-rows` accepts.

## Configuration

Two lenses are listed in the `lenses` block of `config/monocle.php`, each under its class. `true` runs a lens with its defaults, `false` turns it off, and an array passes options. See [Lenses](../configuration.md#lenses).

- `ExeQue\Monocle\Lenses\Packages\Filament\FilamentLens` writes sections `filament` and `filament_context`.
- `ExeQue\Monocle\Lenses\Packages\Filament\FilamentLivewireLens` adds the Filament fields to section `livewire`. It has no options and runs only while `LivewireLens` is on.

| Option | Lens | Default | Effect |
|---|---|---|---|
| `large_table_records` | `FilamentLens` | `500` | a table without pagination that loads more records than this gets a warning and `large: true` |

Other settings that affect it:

- [`except.livewire`](../configuration.md#except) - Livewire updates whose components all match these names are not recorded. Filament component names can be listed here to leave out a dashboard widget that polls.
- [`dashboard.tabs`](../configuration.md#dashboard) - `FilamentLens::class` in the list places the Filament tab.
- Impersonation with `stechstudio/filament-impersonate` is recorded by `FilamentImpersonateLens` - see [Auth](auth.md#impersonation).

```php
use ExeQue\Monocle\Lenses\Packages\Filament\FilamentLens;
use ExeQue\Monocle\Lenses\Packages\Filament\FilamentLivewireLens;
use ExeQue\Monocle\Lenses\Packages\Livewire\LivewireLens;

'lenses' => [
    LivewireLens::class => true,

    FilamentLens::class => [
        'large_table_records' => 1000,
    ],
    FilamentLivewireLens::class => true,
],
```

## Requirements and limits

- Needs `filament/filament`. Without it both lenses do nothing.
- The data entered in action and resource forms is not recorded, only the names of the fields that failed validation.
- A table is recorded as it was last rendered in the request. A table that cannot describe itself is left out.
- Database and broadcast notifications are recorded where they are delivered, usually a queued job, not in the request that sent them.
- Records saved outside a resource's create or edit page, such as from an action, do not get a `record` row; the [Eloquent](eloquent.md) lens shows them.
