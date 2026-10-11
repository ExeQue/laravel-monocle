<!-- Part of the Monocle documentation - see README.md -->

# Response cache

Part of [what gets recorded](../lenses.md).

![The Response cache tab](../images/54-lens-response-cache-light.webp#gh-light-mode-only)
![The Response cache tab](../images/54-lens-response-cache-dark.webp#gh-dark-mode-only)

The response cache lens records whether a response came from `spatie/laravel-responsecache`, and when the response cache was cleared. On a hit your controller did not run, so a change to it shows nothing until the cached entry expires or the cache is cleared.

## Rows

### Section `response_cache`: one row per event the package reports

| Field | Meaning | Unit | Missing when |
| --- | --- | --- | --- |
| `event` | `hit`, `miss`, `clearing`, `cleared` or `clearing_failed`. | - | never |
| `age_seconds` | How old the cached response was. | seconds | not a `hit`, or the package did not report it |
| `tags` | The cache tags of the cached response. | - | not a `hit`, or the response has no tags |
| `time` | When it happened, counted from the start of the capture. | ms | never |
| `origin` | The line in your code that led to it. | - | no application code was on the way |

`event` means:

| Event | Meaning |
| --- | --- |
| `hit` | The response was served from the cache. The controller did not run. |
| `miss` | The response was not in the cache, so the controller ran. |
| `clearing` | Clearing the response cache started. |
| `cleared` | The response cache was cleared. |
| `clearing_failed` | Clearing the response cache failed. |

## Totals, warnings and errors

The summary gets one total when the capture has at least one row:

| Total | Label | Value |
| --- | --- | --- |
| `response_cache` | response cache | `hit` when there is a `hit` row, else `miss` when there is a `miss` row, else `cleared`. |

Warning, on a hit:

- `Served from the response cache ({age} s old); the controller did not run. Clear it (php artisan responsecache:clear) to see a change.` - the capture has a `hit` row. The age part is left out when the age is not known.

This lens reports no errors. A `clearing_failed` row gives no warning or error. See [Warnings and errors](../dashboard.md#warnings-and-errors) for how warnings are shown.

## In the dashboard

The **Response cache** tab lists the rows in the order they happened, with a column for each field that has a value in some row. There are no grouping chips. Click a row to fold it out to every field and the line in your code that led to it.

The overview shows `response cache: hit`, `miss` or `cleared` among the totals.

## For agents

Check whether the latest response came from the cache:

```json
{
  "tool": "monocle-rows",
  "arguments": {"section": "response_cache", "fields": ["event", "age_seconds", "tags"]}
}
```

Find recent requests that touched the response cache:

```json
{
  "tool": "monocle-list",
  "arguments": {"type": "request", "has": ["response_cache"], "since": "15m"}
}
```

Then see whether one was a hit, with the warning and the age of the entry:

```json
{
  "tool": "monocle-show",
  "arguments": {"id": "<capture id>", "sections": ["summary", "response_cache"]}
}
```

See [For AI agents](../agents.md#monocle-list) for every argument.

## Configuration

Turn the lens on or off under `lenses` in `config/monocle.php`, keyed by `ResponseCacheLens::class`: `true` runs it, `false` turns it off. See [Lenses](../configuration.md#lenses). The lens has no options of its own.

Other settings that affect this lens:

- [`origin`](../configuration.md#origin) - whether rows get an `origin`, and how many lines of the call chain it has.
- [`except`](../configuration.md#except) - requests to paths in `except.paths` are not recorded, so their cache hits are not either.
- [`dashboard.tabs`](../dashboard.md#configuration) - where the Response cache tab sits among the tabs.
- [Masking](../configuration.md#masking) does not apply to this lens.

```php
use ExeQue\Monocle\Lenses\Packages\Spatie\ResponseCacheLens;

'lenses' => [
    // ...
    ResponseCacheLens::class => true,
],
```

## Requirements and limits

- Needs `spatie/laravel-responsecache`. Without it the lens does nothing.
- The cached response itself is not stored.
- Storing a response in the cache after a miss is not recorded.
- The cache key and the time a cached entry has left are not recorded.
