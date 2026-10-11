<!-- Part of the Monocle documentation - see README.md -->

# Dumps

Part of [what gets recorded](../lenses.md).

![The Dumps tab](../images/47-lens-dumps-light.webp#gh-light-mode-only)
![The Dumps tab](../images/47-lens-dumps-dark.webp#gh-dark-mode-only)

The dumps lens records every value passed to `dump()` and `dd()` during a capture, with where it was called. You still see the output where you normally would. A capture ended by `dd()` is still stored, marked as ended at shutdown.

## Rows

Every row in the `dumps` section has these fields:

| Field | Meaning | Unit | Missing when |
|---|---|---|---|
| `value` | The dumped value as text, the way `dump()` prints it, with the properties listed under `mask.objects` masked | | never |
| `tokens` | The same text split into styled pieces, used by the dashboard to colour it | | never |
| `type` | Type of the value, such as `array`, `string`, `int` or `App\Models\User` | | never |
| `label` | The name of a named argument (`dump(user: $user)`), or the position (`1`, `2`, ...) when several values are dumped in one call | | `null` when a single value was dumped without a name |
| `is_dd` | `true` when the value came from `dd()` (also `->dd()` on a collection or query) | | never |
| `time` | When the value was dumped, counted from the start of the capture | ms | never |
| `origin` | The file and line of your code that called `dump()` or `dd()`, with a short call chain | | `null` when [`origin.enabled`](../configuration.md#origin) is off |

`dump($a, $b)` gives one row per value.

## Totals, warnings and errors

The summary gets these totals when at least one value was dumped:

| Total | Meaning |
|---|---|
| `dumps` | Number of dumped values |
| `dd_calls` | Number of values dumped by `dd()` |

Warnings: when `dd_calls` is above 0, the summary gets one warning: `dd() ended the process before the response was sent.`

This lens reports no errors.

## In the dashboard

The **Dumps** tab lists the dumps in order with the first line of each value, its type, label, whether it came from `dd()`, when it happened and the line of code that called it.

A row folds out to:

- the value as `dump()` prints it, coloured (strings, numbers, property names), with every class it names linked to your editor when one is set up, and a copy button
- whether it was called through `dump()` or `dd()`, and when
- the line of code that called it

## For agents

`monocle-rows`: what the latest capture dumped, and from where. Leave out `tokens`, which repeats the value.

```json
{"section": "dumps", "fields": ["label", "type", "value", "is_dd", "origin"]}
```

`monocle-rows`: the value `dd()` stopped on.

```json
{
  "section": "dumps",
  "where": [{"field": "is_dd", "op": "=", "value": "true"}],
  "fields": ["value", "origin"]
}
```

`monocle-list`: recent captures with dumps left in them.

```json
{"has": ["dumps"], "since": "1h"}
```

## Configuration

The lens is on by default and has no options. In the `lenses` block of `config/monocle.php`, key it by its class: `true` runs it, `false` turns it off (see [Lenses](../configuration.md#lenses)).

Other settings that affect this lens:

- [`mask.objects`](../configuration.md#masking) lists properties to mask in dumped objects, by class or interface. By default the `password`, `remember_token`, `two_factor_secret` and `two_factor_recovery_codes` of every `Authenticatable` (your user models) are masked. On Eloquent models the names are attributes. A list keyed by `DumpLens::class` adds to the shared one for this lens only.
- [`mask.using`](../configuration.md#masking) is the mask used for a property listed without a mask of its own. Empty (`null`) values are left as they are.
- [`origin`](../configuration.md#origin) decides whether rows carry the line of code that called `dump()`.
- [`except`](../configuration.md#except) keeps whole requests and commands out of recording, including their dumps.

Masking only changes what Monocle stores. The output you see from `dump()` itself is not masked.

```php
// config/monocle.php
use App\Models\Card;
use ExeQue\Monocle\Lenses\DumpLens;
use ExeQue\Monocle\Support\Masks\FullMask;
use Illuminate\Contracts\Auth\Authenticatable;

'lenses' => [
    // ...
    DumpLens::class => true,    // false to turn it off
],

'mask' => [
    // ...
    'objects' => [
        Authenticatable::class => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'],
        Card::class => ['number' => FullMask::class],
        DumpLens::class => [Authenticatable::class => ['email']],
    ],
],
```

## Requirements and limits

- Nothing to install: it uses Symfony's VarDumper, which Laravel ships with.
- Only `dump()`, `dd()` and other calls that go through VarDumper are recorded. `var_dump()`, `print_r()` and `echo` are not.
- Only objects whose class or interface is listed under `mask.objects` are masked. Arrays and strings are dumped as they are, so a token in a plain array is stored unmasked.
