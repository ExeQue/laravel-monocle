<!-- Part of the Monocle documentation - see README.md -->

# Exceptions

Part of [what gets recorded](../lenses.md).

![The Exceptions tab](../images/38-lens-exceptions-light.webp#gh-light-mode-only)
![The Exceptions tab](../images/38-lens-exceptions-dark.webp#gh-dark-mode-only)

The exceptions lens records every exception reported to Laravel's exception handler during a capture: the one that ended a request, and those reported with `report()` or `rescue()` while the request carried on. Each row says where the exception was thrown, the lines of your code it passed through and the exceptions it wraps.

## Rows

Every row in the `exceptions` section has these fields:

| Field | Meaning | Unit | Missing when |
|---|---|---|---|
| `class` | Exception class | | never |
| `message` | Exception message | | never (empty string when there is none) |
| `code` | Exception code | | never (usually 0) |
| `file` | Where it was thrown, as `path:line` relative to the project | | never |
| `frames` | Up to ten lines of your own code from the stack trace, each as `path:line Class->method` (or `Class::method`, or a function name). Frames inside `vendor/` and inside Monocle are left out. A call through a facade is named after the facade, such as `Cache::remember` | | empty when no frame of your code is on the stack |
| `previous` | The exceptions it wraps, outermost first, each with `class`, `message` and `file`; up to ten | | empty when it wraps none |
| `source` | Who defined the exception class: `native` for a class built into PHP, `app` for one of your own, or the Composer package, such as `laravel/framework` or `guzzlehttp/guzzle` | | never |
| `unhandled` | `true` on the exception that became the response; `false` on exceptions that were reported while the request carried on | | never |
| `time` | When it was reported, counted from the start of the capture | ms | never |

Exception rows have no `origin` field: `file` and `frames` say where they came from.

## Totals, warnings and errors

The summary gets these totals when at least one exception was recorded:

| Total | Meaning |
|---|---|
| `exceptions` | Number of exceptions |
| `exceptions_unhandled` | Number of exceptions that became the response |
| `exceptions_distinct` | Number of different exception classes, when some class occurred more than once; 0 when every exception has its own class |
| `exceptions_from_{source}` | One total per source, such as `exceptions_from_native`, `exceptions_from_app` or `exceptions_from_laravel/framework`. PHP's own come first, then the app, then packages |

Errors: every recorded exception is an error. The summary lists the first three as `{class}: {message} ({file})`, such as `RuntimeException: disk full (app/Jobs/ImportAlbum.php:42)`. `exceptions` holds the full count.

This lens reports no warnings.

## In the dashboard

The **Exceptions** tab starts with the totals (the error messages stay on the overview, since the rows below are the same exceptions), then lists the exceptions in the order they were reported: class, message (cut at 80 characters, full text on hover), file, source and whether it was unhandled. Columns without any values, such as `code` when it is 0 everywhere, are left out. Group the rows **by exception** (class) or **by source**.

A row folds out to:

- the exception and the exceptions it wraps, each with class, message and file, with a button that copies the whole chain
- the stack: the lines of your code it passed through, in order, linked to your editor when one is set up, with a copy button
- the exception code (when not 0) and when it was reported

![An exception folded out](../images/19-exception-details-light.webp#gh-light-mode-only)
![An exception folded out](../images/19-exception-details-dark.webp#gh-dark-mode-only)

## For agents

`monocle-show`: whether the latest capture threw, with the error messages in the summary and the exceptions themselves.

```json
{"id": "latest", "sections": ["summary", "exceptions"]}
```

`monocle-rows`: the exception that became the response, with the code that led to it.

```json
{
  "section": "exceptions",
  "where": [{"field": "unhandled", "op": "=", "value": "true"}],
  "fields": ["class", "message", "file", "frames", "previous"]
}
```

`monocle-list`: recent captures that recorded exceptions.

```json
{"has": ["exceptions"], "since": "1h"}
```

## Configuration

The lens is on by default and has no options. In the `lenses` block of `config/monocle.php`, key it by its class: `true` runs it, `false` turns it off (see [Lenses](../configuration.md#lenses)).

Other settings that affect this lens:

- [`except`](../configuration.md#except) keeps whole requests and commands out of recording, including their exceptions.
- Exceptions your application does not report (listed in the exception handler's `dontReport`, or ignored by Laravel by default, such as 404s and validation errors) are not recorded. Change that in your exception handler, not in Monocle.

No mask settings apply to this lens: messages are kept as they are.

```php
// config/monocle.php
use ExeQue\Monocle\Lenses\ExceptionsLens;

'lenses' => [
    // ...
    ExceptionsLens::class => true,    // false to turn it off
],
```

## Requirements and limits

- Nothing to install: it hooks into Laravel's exception handler.
- Only reported exceptions are recorded. An exception caught in a `try` block and not passed to `report()` is not.
- `unhandled` is only set for requests. In a job or command, the failure shows in the capture's own status.
- The stack keeps at most ten frames of your own code; vendor frames are left out.
- Exception messages are not masked.
