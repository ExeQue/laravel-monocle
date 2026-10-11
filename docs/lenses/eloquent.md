<!-- Part of the Monocle documentation - see README.md -->

# Eloquent

Part of [what gets recorded](../lenses.md).

![The Models tab](../images/34-lens-eloquent-light.webp#gh-light-mode-only)
![The Models tab](../images/34-lens-eloquent-dark.webp#gh-dark-mode-only)

The Eloquent lens counts how many models of each class a request, job or command retrieved, created, updated, deleted, restored and force deleted. Every write is also listed on its own with the attributes it changed, old and new values side by side.

## Rows

Section `models`, one row per model class and action, most frequent first. Only written when at least one model event happened.

| Field | Meaning | Unit | Missing when |
| --- | --- | --- | --- |
| `model` | The model class. | - | never |
| `action` | `retrieved`, `created`, `updated`, `deleted`, `restored` or `forceDeleted`. | - | never |
| `count` | How many times this action happened to models of this class. | count | never |

Section `model_changes`, one row per write, in the order they happened. Retrieved models are only counted, not listed here.

| Field | Meaning | Unit | Missing when |
| --- | --- | --- | --- |
| `model` | The model class. | - | never |
| `key` | The model's primary key. | - | `null` when the model has no key |
| `action` | `created`, `updated`, `deleted`, `restored` or `forceDeleted`. | - | never |
| `changes` | One `{field, from, to}` per attribute. On `created`, every attribute with `from` set to `null`. On `deleted` and `forceDeleted`, every attribute with `to` set to `null`. On `updated` and `restored`, only the attributes that changed. Values are the raw attribute values, before casts. Attributes in the model's `$hidden` are left out. | - | empty list when nothing visible changed |
| `connection` | Name of the model's database connection. | - | never |
| `rolled_back` | `true` when the write happened inside a transaction on the same connection that was later rolled back, so it never reached the database. | - | absent when the write was not rolled back |
| `time` | When the write happened, from the start of the capture. | ms | never |
| `origin` | The line in your code that made the write. | - | `null` when [`origin`](../configuration.md#origin) is turned off |

## Totals, warnings and errors

| Total | Label | Meaning |
| --- | --- | --- |
| `models_retrieved` | retrieved | Models retrieved. |
| `models_created` | created | Models created. |
| `models_updated` | updated | Models updated, restores included. |
| `models_deleted` | deleted | Models deleted, force deletes included. |
| `models_top_retrieved` | - | The three model classes retrieved most, with their counts. |

This lens reports no warnings and no errors. Queries behind the models, including N+1 loading, are warned about by the [Database lens](database.md).

## In the dashboard

The **Models** tab starts with the totals and shows two tables:

- The model counts: model class, action and count, most frequent first.
- **Changes**: every write with its model, key, action, connection and the line of your code that made it. The changes show as a small table of field, from and to: a new value is shown in green, an old one in red, and `null` is greyed out. A write in a rolled back transaction is marked `rolled back` and tinted yellow.

A row folds out to every field in full.

## For agents

See which models a request loaded and how many of each with `monocle-rows`:

```json
{"section": "models", "where": [{"field": "action", "op": "=", "value": "retrieved"}]}
```

See what a request changed on one model class, old and new values included, with `monocle-rows`:

```json
{
  "section": "model_changes",
  "where": [{"field": "model", "op": "contains", "value": "Order"}],
  "fields": ["key", "action", "changes", "origin"]
}
```

Find writes that were rolled back and never saved with `monocle-rows`:

```json
{"section": "model_changes", "where": [{"field": "rolled_back", "op": "not_null"}]}
```

## Configuration

The lens is listed in the `lenses` block of `config/monocle.php`, keyed by its class. Set it to `true` to run it or `false` to turn it off. See [Lenses](../configuration.md#lenses).

The lens has no options.

Other settings that affect this lens:

- [`enabled`](../configuration.md#enabled) - when Monocle is off, nothing is recorded.
- [`except`](../configuration.md#except) - requests and commands listed there are not recorded.
- [`origin`](../configuration.md#origin) - whether each write gets the line of your code that made it.
- [`dashboard.tabs`](../dashboard.md#configuration) - where the Models tab goes among the tabs; it is listed as `EloquentLens::class`.
- [Masking](../configuration.md#masking) does not apply to model changes. Attributes listed in a model's `$hidden` are always left out; use that to keep values such as passwords out of the change list.

```php
use ExeQue\Monocle\Lenses\EloquentLens;

'lenses' => [
    EloquentLens::class => true,
],
```

## Requirements and limits

No extra packages are needed.

- Only writes that fire Eloquent model events are recorded. Query builder writes such as `Model::where(...)->update()`, `delete()` or `insert()`, and saves made with `saveQuietly()` or inside `withoutEvents()`, do not appear here; they still appear as queries in the [Database lens](database.md).
- Retrieved models are counted per class, not listed one by one.
- Values in `changes` are raw attribute values, not cast values.
