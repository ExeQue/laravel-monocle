<!-- Part of the Monocle documentation - see README.md -->

# Inertia

Part of [what gets recorded](../lenses.md).

![The Inertia tab](../images/52-lens-inertia-light.webp#gh-light-mode-only)
![The Inertia tab](../images/52-lens-inertia-dark.webp#gh-dark-mode-only)

Section `inertia`: one row per prop of an Inertia page - its type (plain, closure, always, optional, lazy, defer, merge, scroll, once), whether it is shared, whether it was sent, its size as JSON, its defer group and merge strategy, and, for a prop that was not sent, why: deferred, left out by a partial reload, optional, already loaded once, or rescued after it failed. Props resolved by a closure in your code - deferred, optional, lazy or a plain closure - show where the closure is defined, linked to your editor. Expanding a prop that was sent shows its value, with secrets masked like request input. The time each of those closures took to resolve is shown with the prop and as a span on the timeline, with the queries it ran under it; a deferred prop is timed on the request that loads it.

Section `inertia_pages`: the page itself - component, URL, asset version, whether it was a first load or an Inertia visit, the props a partial reload asked for (only or except), the deferred groups, the size of the whole page and whether history is encrypted or cleared. The Inertia tab shows these facts above the props, and groups the props by type or by scope (shared or page).

Needs `inertiajs/inertia-laravel`.

Warnings: a prop bigger than `large_prop_bytes`, a page bigger than `large_page_bytes`, and a shared prop sent on a partial reload that did not ask for it (such as a reload with only an except list). Wrap such a prop in `Inertia::always()` if it is meant to be sent every time.

Options: `large_prop_bytes` (50000) and `large_page_bytes` (200000); `max_value_bytes` (20000) - a prop value larger than this is shown as its size instead of its value.

Inertia responses are also shown as a card on the Request tab: the component, its props and, on a partial reload, which props were asked for. This works for Inertia visits and for the page embedded in the first load.
