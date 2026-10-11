<!-- Part of the Monocle documentation - see README.md -->

# Session

Part of [what gets recorded](../lenses.md).

![The Session tab](../images/31-lens-session-light.webp#gh-light-mode-only)
![The Session tab](../images/31-lens-session-dark.webp#gh-dark-mode-only)

`SessionLens` records what the session held once a request was handled, and what that request changed in it. It records for every request that has a session, which in a default Laravel application means routes in the `web` middleware group.

## Rows

The `session` section is one set of facts per capture, not a list of rows.

| Key | Meaning | Missing when |
|---|---|---|
| `driver` | the session driver from `session.driver` (`file`, `database`, `redis`, ...) | - |
| `id` | the first 12 characters of a SHA-256 hash of the session id. Use it to tell sessions apart across captures; the id itself is never stored. | - |
| `data` | everything in the session after the request was handled, masked. Flash bookkeeping is left out. | empty when the session was empty |
| `changes` | one entry per key the request added, changed or removed: `field` (dot notation, for example `cart.items.0.qty`), `from` and `to`, both masked. `from` is null for a new key, `to` is null for a removed one. | null when the session was started without Laravel's `StartSession` middleware, so there was nothing to compare with; an empty list when nothing changed |
| `flash` | the keys in the session's flash data: those flashed during this request for the next one, and those flashed by the previous request | empty when nothing was flashed |

Values under keys that match [`mask.inputs`](../configuration.md#masking) are masked, at any depth. With the default pattern that includes the CSRF token (`_token`) and keys such as `password_hash_web`.

## Totals, warnings and errors

The lens adds no totals, warnings or errors to the summary.

## In the dashboard

The Session tab has no table and no groupings. It shows:

- a line with the driver, the session id hash (with a copy button) and the keys flashed for the next request,
- **Changed by this request**: a table of each changed key with its value before and after, or "This request did not change the session.",
- **Contents**: every key in the session with its value, or "The session was empty."

## For agents

The session of the latest request:

```json
monocle-show {"id": "latest", "sections": ["session"]}
```

Answers what the session held, which keys the request changed and what was flashed, for example whether a login stored `url.intended` or a form flashed its errors.

The session facts on their own:

```json
monocle-rows {"id": "latest", "section": "session"}
```

Returns the `driver`, `id`, `data`, `changes` and `flash` keys as one object.

## Configuration

The lens is listed under `lenses` in `config/monocle.php`, keyed by `SessionLens::class`: `true` turns it on, `false` turns it off. See [Lenses](../configuration.md#lenses).

The lens has no options of its own.

Other configuration that affects this lens:

| Key | Effect |
|---|---|
| [`mask.inputs`](../configuration.md#masking) | session keys whose values are masked, and `mask.using` the mask used for them |
| [`except.paths`](../configuration.md#except), [`except.livewire`](../configuration.md#except) | requests left out there are not recorded, so their session is not either |
| [`dashboard.tabs`](../dashboard.md#configuration) | where the Session tab sits; it comes right after Request by default |

A mask entry keyed by `SessionLens::class` applies to this lens only and wins over the shared one.

```php
use ExeQue\Monocle\Lenses\SessionLens;
use ExeQue\Monocle\Support\Masks\PartialMask;

'lenses' => [
    SessionLens::class => true,
],

'mask' => [
    'inputs' => ['/pass|token|secret|authorization/i', SessionLens::class => '/impersonate/i'],
    'using' => PartialMask::class,
],
```

## Requirements and limits

- No package is needed.
- Only HTTP requests with a session are recorded. API routes without the session middleware, jobs and commands have no `session` section.
- The session is read once the request was handled. Changes made later, in `terminate` or by terminating middleware, are not included.
- `changes` needs the session to be started by Laravel's `StartSession` middleware. A session started another way still shows its contents, but no changes.
