<!-- Part of the Monocle documentation - see README.md -->

# Scheduler

Part of [what gets recorded](../lenses.md).

![A schedule:run capture](../images/45-lens-schedule-light.webp#gh-light-mode-only)
![A schedule:run capture](../images/45-lens-schedule-dark.webp#gh-dark-mode-only)

The Scheduler lens records what `php artisan schedule:run` did with each task that was due: when it started, and whether it finished, was skipped or failed. The rows land on the `schedule:run` command capture.

## How scheduled tasks are captured

`schedule:run` is recorded like any other [artisan command](artisan-commands.md), and this lens adds the `scheduled_tasks` section to it. What happens inside each task depends on its kind:

- **Command tasks** (`$schedule->command('orders:prune')`) run in a separate `php artisan` process. That command is recorded as a command capture of its own, which is not linked to the `schedule:run` capture. Find it in the capture list by its name.
- **Closure tasks** (`$schedule->call(...)`) run inside `schedule:run`, so their queries, cache calls and so on are part of the `schedule:run` capture.
- **Job tasks** (`$schedule->job(...)`) dispatch the job from `schedule:run`. It is a row in that capture's Jobs tab and, once a worker runs it, a job capture linked to it; see [Queue](queue.md#how-jobs-are-linked).
- **Shell tasks** (`$schedule->exec(...)`) run a separate process that Monocle does not record.

`schedule:work` itself is not recorded, but the `schedule:run` it starts every minute is, so each minute with due tasks gets its own capture.

## Rows

Section `scheduled_tasks` has one row per event of a task: a `starting` row when the task starts, then a row with its outcome. A skipped task has only the `skipped` row.

| Field | Meaning | Unit | Missing when |
|---|---|---|---|
| `command` | The command line of the task, or for a closure or job task its name or description (`Callback` when it has neither) | - | never |
| `expression` | The cron expression, such as `0 3 * * *` | - | never |
| `description` | The task's name or description | - | null unless you gave the task a `name()` or `description()` |
| `outcome` | `starting`, `finished`, `finished_in_background`, `skipped` or `failed` | - | never |
| `runtime_ms` | How long the task ran | ms | only set on `finished` rows |
| `exception` | `class`, `message`, `file` (relative path and line) and `previous` (earlier exceptions in the chain) | - | only set on `failed` rows |
| `time` | When the row was recorded, counted from the start of the capture | ms | never |

Outcomes:

- `finished`: the task ran to the end in the `schedule:run` process.
- `finished_in_background`: a task with `runInBackground()` finished. See the limits below.
- `skipped`: the task was due, but a `when()`, `skip()` or similar condition kept it from running, or the schedule was paused.
- `failed`: the task threw an exception.

Tasks that were not due at that minute get no rows.

## Totals, warnings and errors

When the capture has rows in `scheduled_tasks`:

| Total | Label | Meaning |
|---|---|---|
| `scheduled_tasks_run` | run | Rows with outcome `finished` or `finished_in_background` |
| `scheduled_tasks_skipped` | skipped | Rows with outcome `skipped` |
| `scheduled_tasks_failed` | failed | Rows with outcome `failed` |

Error: `Scheduled task failed: <command>` for every failed task. The lens reports no warnings.

## In the dashboard

The `schedule:run` capture has a **Scheduled tasks** tab listing the rows in order: command, expression, description, outcome, runtime, exception and when each was recorded. It starts with the totals of the section, such as the number of failed tasks. Click a row to fold it out to every field in full, including the full exception. The tab sits next to the **Command** tab with the facts of `schedule:run` itself.

## For agents

Did last night's tasks run, and which failed. `monocle-list` with:

```json
{"type": "command", "search": "schedule:run", "has_errors": true, "since": "24h"}
```

then `monocle-rows` with the id it returned:

```json
{"id": "01a12c76-...", "section": "scheduled_tasks", "where": [{"field": "outcome", "op": "=", "value": "failed"}], "fields": ["command", "expression", "exception"]}
```

Why a task did not run: list the rows of the `schedule:run` capture for that minute and look for a `skipped` row, or no row at all (the task was not due). `monocle-rows` with:

```json
{"id": "01a12c76-...", "section": "scheduled_tasks", "search": "orders:prune"}
```

What a command task did inside: it is its own command capture. `monocle-list` with `{"type": "command", "search": "orders:prune"}`, then `monocle-show` on it. See [For AI agents](../agents.md#tools).

## Configuration

Turn the lens on or off in the `lenses` block of `config/monocle.php`, keyed by the lens class: `true` runs it, `false` turns it off. See [Lenses](../configuration.md#lenses).

The lens has no options.

Other settings that affect this lens:

- The [Artisan commands](artisan-commands.md) lens (`CommandLens`) must be on, because the rows belong to the `schedule:run` command capture.
- [`except.commands`](../configuration.md#except): `schedule:work` and `schedule:finish` are listed by default. Do not add `schedule:run`, or nothing is recorded. Adding a task's command there stops that command's own capture, but its rows on `schedule:run` stay.

```php
use ExeQue\Monocle\Lenses\CommandLens;
use ExeQue\Monocle\Lenses\ScheduleLens;

'lenses' => [
    CommandLens::class => true,
    ScheduleLens::class => true,
],

'except' => [
    'commands' => [
        // keep these from the defaults; schedule:run must not be listed
        'schedule:work', 'schedule:finish',
        // ...
    ],
],
```

## Requirements and limits

No extra packages are needed.

- Only `schedule:run` (directly, from cron or from `schedule:work`) is covered. `schedule:test` and running a task's command by hand are recorded as plain command captures, without `scheduled_tasks` rows.
- A task with `runInBackground()` shows only its `starting` row on the `schedule:run` capture: its end is reported later by `schedule:finish` in another process, which is not recorded. The task's command is still recorded as its own command capture.
- Tasks skipped because the application is in maintenance mode get no row.
- The task's output (`sendOutputTo()`, `emailOutputTo()`) is not recorded.
