<!-- Part of the Monocle documentation - see README.md -->

# Filament

Part of [what gets recorded](../lenses.md).

Section `filament`: what Filament did during the request, one row per thing, grouped by kind:

- `action` - an action mounted or run on a page, table or relation manager: its name, the component, the status (`success`, `failed`, `halted` when it was halted or cancelled, `invalid` when its form failed validation, with the fields that failed, `error` when it threw, `modal opened` when it opened its modal and waits for the user, `stopped` when it did not run, such as a disabled or unauthorized action), its arguments, the record of a table action, whether it is a bulk action, the notifications it sent and how long it took. The data entered in its form is not recorded.
- `notification` - a notification sent with `Filament\Notifications\Notification`: title, status, body and channel - `flash` for `send()`, `database` for `sendToDatabase()`, `broadcast` for `broadcast()` - and the action that sent it. Database and broadcast notifications are queued, so they show up where they are delivered, such as the capture of the queued job.
- `table` - every table rendered: the active filters, the search, column searches, sort, grouping, the active tab, the page and records per page, the records shown and the total, and whether loading was deferred.
- `record` - a record created or updated on a resource's create or edit page.

Section `filament_context`: the panel (its id, path and auth guard), the tenant, the page, its resource and the record it shows. The Filament tab shows these facts above the rows.

With Livewire recorded too, the rows of Filament components in the `livewire` section say what they are to Filament - `resource page`, `page`, `relation manager`, `widget`, `table` or `component` - with the panel, the resource and the record (`owner_record` on a relation manager).

Needs `filament/filament`. Lenses: `Filament\FilamentLens` (sections `filament` and `filament_context`) and `Filament\FilamentLivewireLens` (adds to `livewire`).

Errors: an action that threw an exception, and an action that ended with `failure()`. Warning: a table without pagination that loaded more than `large_table_records` records.

Option: `large_table_records` (500).
