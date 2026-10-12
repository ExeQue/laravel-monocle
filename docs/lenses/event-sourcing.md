<!-- Part of the Monocle documentation - see README.md -->

# Event sourcing

![The Event sourcing tab](../images/59-lens-event-sourcing-light.webp#gh-light-mode-only)
![The Event sourcing tab](../images/59-lens-event-sourcing-dark.webp#gh-dark-mode-only)

The Event sourcing lens records what [spatie/laravel-event-sourcing](https://github.com/spatie/laravel-event-sourcing) did in a request, job or command: the events it stored, the projectors and reactors that handled them and how long each took, and the aggregate roots it retrieved and persisted. It writes one row per stored event to section `event_sourcing` and one row per aggregate root to section `aggregates`.

## Rows

### Stored events

One row for each event the capture stored, or handled (a queued projector handles events another capture stored).

| Field | Meaning | Unit | Missing when |
|---|---|---|---|
| `event` | the event class | - | never |
| `stored_event_id` | the id of the stored event | - | never |
| `aggregate_uuid` | the uuid of the aggregate root that recorded it | - | null for an event fired on its own |
| `aggregate_version` | the aggregate root's version after this event | - | never |
| `stored` | true when this capture stored the event | - | never |
| `queued` | true when some of its handlers run on the queue | - | never |
| `properties` | the event's properties as stored, masked like request input | - | empty list when it has none |
| `handlers` | each projector and reactor that handled it, in the order they ran: `handler` (class), `type` (`projector` or `reactor`), `mode` (`sync`, or `queued` when it ran from the queue job), `status` (`ok` or `failed`), `duration` and `exception` | - | empty list when nothing handled it in this capture |
| `duration` | the time of its handlers together | ms | null when nothing handled it in this capture |
| `time` | when the event was first seen | ms since the capture started | never |
| `origin` | the line in your code that stored or handled it | - | never |

Each handled event is a span of kind `event_sourcing` on the Timeline, named after the event, with a span of kind `event_sourcing.handler` per projector and reactor under it. The queries and calls a projector made sit under its span.

### Aggregate roots

| Field | Meaning | Unit | Missing when |
|---|---|---|---|
| `action` | `retrieved` when the aggregate root was rebuilt from its events, `persisted` when it stored the events it recorded | - | never |
| `aggregate` | the aggregate root class | - | never |
| `uuid` | its uuid | - | never |
| `events` | how many events it was rebuilt from, or how many it stored | - | never |
| `snapshot` | true when it was rebuilt from a snapshot and only the events after it | - | never |
| `time` | when it started | ms since the capture started | never |
| `duration` | how long reading or storing the events took | ms | never |

## Totals, warnings and errors

When either section has rows, the summary gets:

| Total | Label | Value |
|---|---|---|
| `event_sourcing_ms` | in handlers | the time of all projectors and reactors together, in ms |
| `event_sourcing_stored` | stored | the number of events this capture stored |
| `event_sourcing_handled` | handler calls | the number of times a projector or reactor ran |
| `event_sourcing_aggregates` | aggregates | the number of aggregate rows |

Warning: one for each aggregate root rebuilt from at least `snapshot_threshold` events without a snapshot, reading "Aggregate `class` (`uuid`) was rebuilt from N events without a snapshot." Rebuilding reads every event the aggregate ever recorded; a snapshot of the aggregate cuts that down.

Error: one for each projector or reactor that failed, at most five, reading "Projector `class` failed handling `event`: `message`". With `catch_exceptions` off (the package's default) the exception also leaves the request, and the [Exceptions](exceptions.md) lens has it too.

## In the dashboard

The **Event sourcing** tab shows the totals above a table of stored events: event, aggregate uuid, version, whether this capture stored it, whether it has queued handlers, and the handlers' time. Rows can be grouped by event. A row with a failed handler is reported on the tab as an error. Below it, the **Aggregates** table lists the aggregate roots retrieved and persisted, groupable by aggregate.

Each event folds out to its facts, a table of the handlers that ran - each linking to its class in your editor, with the exception of one that failed - and its properties.

![A stored event folded out](../images/59-lens-event-sourcing-details-light.webp#gh-light-mode-only)
![A stored event folded out](../images/59-lens-event-sourcing-details-dark.webp#gh-dark-mode-only)

The **Timeline** tab shows an `event_sourcing` span per handled event, with its handlers under it.

## For agents

Which events did the latest request store, and which handlers ran?

```json
{ "tool": "monocle-show", "id": "latest", "sections": ["summary", "event_sourcing", "aggregates"] }
```

Projectors and reactors that failed:

```json
{
  "tool": "monocle-rows",
  "section": "event_sourcing",
  "search": "failed",
  "fields": ["event", "aggregate_uuid", "handlers"]
}
```

Where the time of a slow projector went:

```json
{ "tool": "monocle-show", "id": "latest", "sections": ["timeline"], "kinds": ["event_sourcing", "event_sourcing.handler", "query"] }
```

To find captures that stored events, use `monocle-list` with `"has": ["event_sourcing"]`. See [For AI agents](../agents.md#monocle-rows) for every filter `monocle-rows` accepts.

## Configuration

The lens is listed in the `lenses` block of `config/monocle.php` under its class, `ExeQue\Monocle\Lenses\Packages\Spatie\EventSourcing\EventSourcingLens`. `true` runs it with its defaults, `false` turns it off, and an array sets its options. See [Lenses](../configuration.md#lenses).

| Option | Default | Effect |
|---|---|---|
| `snapshot_threshold` | `100` | warn when an aggregate root is rebuilt from at least this many events without a snapshot |

```php
use ExeQue\Monocle\Lenses\Packages\Spatie\EventSourcing\EventSourcingLens;

'lenses' => [
    EventSourcingLens::class => [
        'snapshot_threshold' => 250,
    ],
],
```

Other settings that affect it:

- [Masking](../configuration.md#masking) - `mask.inputs` masks the event properties by name. Entries keyed by `EventSourcingLens::class` apply to this lens only.
- [`dashboard.tabs`](../configuration.md#dashboard) - `EventSourcingLens::class` in the list places the Event sourcing tab.

## How it hooks in

The package fires no events around its handlers, so the lens wraps two of its services:

- **The projectionist**, replaced by a subclass that times each projector and reactor it calls. While no capture is recording it hands every call to the package's own code unchanged.
- **The stored event repository** - the one configured under `event-sourcing.stored_event_repository`, and the `StoredEventRepository` binding - wrapped so it notes every event stored and counts the events an aggregate root is rebuilt from. Methods your own repository adds are passed on.

## Requirements and limits

- Needs `spatie/laravel-event-sourcing` 7. Without it the lens does nothing.
- The lens follows the projectionist's own steps to time each handler (their weight, the projecting flag, catching exceptions). A future version of the package that changes those steps may need a matching Monocle release.
- Code that type-hints your concrete repository class and resolves it from the container gets the wrapper, which only implements `StoredEventRepository`. Resolve the interface instead, or turn the lens off.
- Replays (`event-sourcing:replay`) and the command bus are not recorded yet. A replay's queries still show in the command's capture.
- An aggregate root is named from the call stack; a repository read from anywhere else (your own query on stored events) gets no aggregate row.
