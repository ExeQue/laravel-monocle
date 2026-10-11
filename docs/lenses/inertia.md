<!-- Part of the Monocle documentation - see README.md -->

# Inertia

Part of [what gets recorded](../lenses.md).

![The Inertia tab](../images/52-lens-inertia-light.webp#gh-light-mode-only)
![The Inertia tab](../images/52-lens-inertia-dark.webp#gh-dark-mode-only)

The Inertia lens records every Inertia page a request returns, on the first load and on Inertia visits and partial reloads. It writes one row per top-level prop to section `inertia` - its type, size, whether it was sent and, if not, why - and one row per page to section `inertia_pages`.

## Rows

### Section `inertia`

A row for every prop that was declared or sent. A dotted key such as `auth.user` counts as part of its top-level prop `auth`.

| Field | Meaning | Unit | Missing when |
|---|---|---|---|
| `prop` | the prop's name | - | never |
| `type` | how it was declared: `plain`, `closure`, `always`, `optional`, `lazy`, `defer`, `merge`, `scroll` or `once` | - | never |
| `shared` | `true` for a shared prop, `false` for a prop of the page | - | never |
| `sent` | whether the prop was in the response | - | never |
| `size_bytes` | the prop's size as JSON | bytes | null when not sent |
| `group` | the defer group of a deferred prop, such as `default` | - | null when not deferred |
| `merge` | how the client merges it: `append`, `prepend` or `deep` | - | null when not merged |
| `omitted` | why it was not sent - see below | - | null when sent |
| `value` | the value that was sent, masked like request input | - | null when not sent; replaced by its size, such as `(84.0 KB, larger than max_value_bytes)`, when larger than `max_value_bytes` |
| `defined_at` | where the closure the prop resolves with is defined in your code, as `file:line` | - | null when the prop is not a closure, or the closure is in a package |
| `duration` | how long that closure took to resolve | ms | null when no closure ran |
| `time` | when the closure started resolving, or when the page was recorded | ms since the capture started | never |
| `large` | `true` when the prop is larger than `large_prop_bytes` | - | null otherwise |
| `shared_on_partial` | `true` when a shared prop was sent on a partial reload that did not ask for it | - | null otherwise |

Why a prop was not sent (`omitted`), checked in this order:

| Value | Meaning |
|---|---|
| `rescued` | resolving it failed and Inertia rescued it |
| `deferred` | it is deferred and loads in a later request |
| `partial reload` | a partial reload did not ask for it |
| `optional` | an optional or lazy prop that was not asked for |
| `loaded once` | a once prop the client already has |
| `omitted` | any other reason |

A deferred prop is timed on the request that loads it.

### Section `inertia_pages`

| Field | Meaning | Unit | Missing when |
|---|---|---|---|
| `component` | the page component, such as `Dashboard` | - | never |
| `url` | the page URL | - | never |
| `version` | the asset version | - | null when not set |
| `visit` | `first load` for a full page load, `inertia` for an Inertia visit | - | never |
| `partial` | on a partial reload: the `component` and the props asked for with `only` or left out with `except` | - | null when not a partial reload |
| `props_sent` | the number of props in the response | count | never |
| `props_omitted` | the number of declared props that were not sent | count | never |
| `payload_bytes` | the size of the whole page as JSON | bytes | never |
| `deferred` | the deferred props by group, such as `{"default": ["stats"]}` | - | null when nothing is deferred |
| `encrypt_history` | whether history encryption is on | - | never |
| `clear_history` | whether the history is cleared | - | never |
| `time` | when the page was recorded | ms since the capture started | never |

Each closure a prop resolves with is also a span of kind `inertia.prop` on the Timeline, named after the prop, with the queries it ran under it.

## Totals, warnings and errors

When the capture has an Inertia page, the summary gets:

| Total | Label | Value |
|---|---|---|
| `inertia_ms` | spent | the time all prop closures took to resolve, in ms |
| `inertia_props` | props sent | the number of props that were sent |
| `inertia_payload_bytes` | payload | the size of all pages as JSON, in bytes |
| `inertia_deferred` | deferred | the number of props left out because they are deferred |

Warnings:

- A prop larger than `large_prop_bytes` (50000 by default): "Inertia prop "`prop`" on `Component` is `size`, defined at `file:line`." The location is added when it is known.
- A page larger than `large_page_bytes` (200000 by default): "Inertia page `Component` is `size`."
- A shared prop sent on a partial reload that did not ask for it, such as every shared prop on a reload with only an `except` list: "Shared Inertia prop "`prop`" was sent on a partial reload of `Component` that did not ask for it." Props declared with `Inertia::always()` are exempt; wrap a prop in it if it is meant to be sent every time.

The Inertia lens reports no errors.

## In the dashboard

The **Inertia** tab shows the totals, then a line with the page facts - component, URL, visit, version, the props a partial reload asked for or left out, the payload size, and whether history is encrypted or cleared - then the props table.

The props table has the columns scope (`shared` or `page`), prop, type, sent (`sent` or `not sent`), size, group, merge, omitted, duration and defined at; `defined_at` links to your editor. Large props and shared props sent on a partial reload are tinted as warnings. Group the rows **in order**, **by type** or **by scope**. Each row folds out to all of its fields, including the value that was sent. The pages are listed as their own table below the props.

The **Request** tab shows the Inertia response as a card titled with the component and its URL: the visit, the version, the partial reload, the props as sent (masked like request input), the deferred and merge props, and whether history is encrypted or cleared. This works for Inertia visits and for the page embedded in the HTML of a first load. See [Response captures](request.md#response-captures).

The **Timeline** tab shows an `inertia.prop` span for every prop closure that ran.

## For agents

Is the page heavy, and which props make it so?

```json
{
  "tool": "monocle-rows",
  "section": "inertia",
  "sort": "size_bytes",
  "dir": "desc",
  "limit": 10,
  "fields": ["prop", "type", "shared", "size_bytes", "defined_at"]
}
```

Which props were left out, and why:

```json
{
  "tool": "monocle-rows",
  "section": "inertia",
  "where": [{ "field": "omitted", "op": "not_null" }],
  "fields": ["prop", "type", "omitted", "group", "defined_at"]
}
```

The page itself and the warnings for it, including what a partial reload asked for:

```json
{ "tool": "monocle-show", "id": "latest", "sections": ["summary", "inertia_pages"] }
```

To find the slow prop closures, ask `monocle-rows` for section `inertia` sorted by `duration`. See [For AI agents](../agents.md#monocle-rows) for every filter `monocle-rows` accepts.

## Configuration

The lens is listed in the `lenses` block of `config/monocle.php` under its class, `ExeQue\Monocle\Lenses\Packages\Inertia\InertiaLens`. `true` runs it with its defaults, `false` turns it off, and an array passes options. See [Lenses](../configuration.md#lenses).

| Option | Default | Effect |
|---|---|---|
| `large_prop_bytes` | `50000` | a prop larger than this, as JSON, gets a warning and `large: true` |
| `large_page_bytes` | `200000` | a page larger than this, as JSON, gets a warning |
| `max_value_bytes` | `20000` | a prop value larger than this is shown as its size instead of its value |

Other settings that affect it:

- [Masking](../configuration.md#masking) - prop values are masked with `mask.inputs`. Key an entry by `InertiaLens::class` to mask more on the Inertia tab only.
- `RequestLens` option `capture` - `'text/html' => InertiaCapture::class` under `response_types` and `'X-Inertia' => InertiaCapture::class` under `headers` give the Request tab its Inertia card. Leave them out to keep only the raw response. See [Request](request.md#response-captures).
- [`dashboard.tabs`](../configuration.md#dashboard) - `InertiaLens::class` in the list places the Inertia tab.

```php
use ExeQue\Monocle\Lenses\Packages\Inertia\InertiaCapture;
use ExeQue\Monocle\Lenses\Packages\Inertia\InertiaLens;
use ExeQue\Monocle\Lenses\Request\JsonCapture;
use ExeQue\Monocle\Lenses\RequestLens;

'lenses' => [
    RequestLens::class => [
        'capture' => [
            'response_types' => [
                'application/json' => JsonCapture::class,
                'application/*+json' => JsonCapture::class,
                'text/html' => InertiaCapture::class,
            ],
            'headers' => [
                'X-Inertia' => InertiaCapture::class,
            ],
        ],
    ],

    InertiaLens::class => [
        'large_prop_bytes' => 50000,
        'large_page_bytes' => 200000,
        'max_value_bytes' => 20000,
    ],
],

'mask' => [
    'inputs' => ['/pass|token|secret|authorization/i', InertiaLens::class => '/email|phone/i'],
],
```

Setting a `capture` key replaces its default, so list every entry you want to keep - see [Request](request.md#response-captures).

## Requirements and limits

- Needs `inertiajs/inertia-laravel`. Without it the lens does nothing.
- Only the server side is recorded: what the page did in the browser, client-side visits answered from history, and prefetched pages that never reach the server are not.
- Props are recorded by their top-level name; nested keys are not listed on their own.
- `defined_at` and `duration` are only known for props that resolve through a closure. A closure defined inside a package has no `defined_at`.
- Values larger than `max_value_bytes` are kept as their size only.
