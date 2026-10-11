<!-- Part of the Monocle documentation - see README.md -->

# Validation

Part of [what gets recorded](../lenses.md).

![The Validation tab](../images/50-lens-validation-light.webp#gh-light-mode-only)
![The Validation tab](../images/50-lens-validation-dark.webp#gh-dark-mode-only)

`ValidationLens` records every validation that runs during a request, job or command: form requests, `$request->validate()`, Livewire's `$this->validate()` and your own `Validator::make()`. Each row shows where the validation came from, the rules per field, the validated input and, when it failed, which rule failed for which field and with what message.

## Rows

One row per validator, recorded when it runs.

| Field | Meaning | Unit | Missing when |
|---|---|---|---|
| `source` | the form request class or Livewire component class that validated | - | null for `$request->validate()`, `Validator::make()` and other inline validation |
| `passed` | whether every rule passed | true/false | - |
| `rules` | field => its rules as one pipe-separated string, for example `required\|email` or `in:"free","pro"` | - | - |
| `failed` | one entry per failed rule: `field`, `rule` (as the validator names it, such as `Required` or `Max`), `parameters` (such as `["3"]` for `max:3`) and `message` | - | empty when the validation passed |
| `input` | the input of the validated fields, masked like request input | - | empty when none of the validated fields were sent |
| `fields` | number of fields with rules | count | - |
| `duration` | time from creating the validator to the end of the validation | ms | - |
| `time` | when the validation started, from the start of the capture | ms | - |
| `origin` | the line in your code that ran the validation, with its call chain | - | - |

Rule objects are recorded as the class you wrote, followed by its public properties as JSON when it has any. Closure rules are recorded as `Closure (file:line)`, pointing at where the closure is defined. Rules such as `Rule::in()` are recorded as their string form.

Only the top-level input keys that have rules are kept: rules on `items.*.name` keep the whole `items` input. Keys matching [`mask.inputs`](../configuration.md#masking) are masked, uploaded files are described by `file`, `size` (bytes) and `mime`, and strings longer than 1000 characters are cut.

## Totals, warnings and errors

| Total | Meaning | Unit |
|---|---|---|
| `validations` | number of validations | count |
| `validation_failed` | number of validations that failed | count |

Both are left out when no validation ran. They are grouped under the validation section on the overview.

The lens raises no warnings or errors. A failed validation is marked as failed in its tab instead, since a 422 is usually the expected answer to bad input.

## In the dashboard

The Validation tab lists the validations in the order they ran, with the source, whether it passed (marked "failed" in yellow when it did not), the rules, the failures, the number of fields, the duration and the origin. Columns that are empty in every row are left out. There are no groupings.

Click a row to fold it out to a table with one line per field: the field, its rules, the input it got (an uploaded file as its name, size and type) and the result, either "passed" or each failed rule with its parameters and message. Below the table are the source, the duration, when it started and the origin, which opens in your editor when one is set up.

## For agents

The failed validations of the latest capture:

```json
monocle-rows {"id": "latest", "section": "validation", "where": [{"field": "passed", "op": "=", "value": "0"}], "fields": ["source", "failed", "input"]}
```

Answers why a form or API call got a 422 or was redirected back: which rule failed for which field, with the message and the input it was given.

Every validation with its rules and where it ran:

```json
monocle-rows {"id": "latest", "section": "validation", "fields": ["source", "rules", "passed", "origin"]}
```

Shows which form request, component or line of code validated, and the rules that applied.

Requests whose validation failed recently:

```json
monocle-list {"type": "request", "status": 422, "has": ["validation"], "since": "30m"}
```

Lists the requests of the last 30 minutes that answered 422 and ran a validation.

## Configuration

The lens is listed under `lenses` in `config/monocle.php`, keyed by `ValidationLens::class`: `true` turns it on, `false` turns it off. See [Lenses](../configuration.md#lenses).

The lens has no options of its own.

Other configuration that affects this lens:

| Key | Effect |
|---|---|
| [`mask.inputs`](../configuration.md#masking) | input keys whose values are masked in `input`, and `mask.using` the mask used for them |
| [`origin`](../configuration.md#origin) | how much of the call chain behind `origin` is recorded, and which packages count as your code |
| [`except`](../configuration.md#except) | requests, Livewire updates and commands left out there are not recorded, so their validation is not either |
| [`dashboard.tabs`](../dashboard.md#configuration) | where the Validation tab sits |

A mask entry keyed by `ValidationLens::class` applies to this lens only and wins over the shared one.

```php
use ExeQue\Monocle\Lenses\ValidationLens;
use ExeQue\Monocle\Support\Masks\FullMask;

'lenses' => [
    ValidationLens::class => true,
],

'mask' => [
    'inputs' => ['/pass|token|secret|authorization/i', ValidationLens::class => '/cpr|ssn/i'],
    'using' => FullMask::class,
],
```

## Requirements and limits

- No package is needed. Livewire components are named as the source only when `livewire/livewire` is installed.
- A validator is recorded once, the first time it runs, however often it is checked again.
- A validator that is made but never run (no `validate()`, `passes()`, `fails()` or `errors()`) is not recorded.
- Validators made before the capture started, or while it was not recording, are not recorded.
- Input outside the validated fields is not recorded here; the full payload is on the [Request](request.md) tab.
- A resolver set with `Validator::resolver()` keeps working, also when it is set after the application booted.
- An application that replaces Laravel's validation factory with its own is not recorded.
