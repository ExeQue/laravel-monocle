<!-- Part of the Monocle documentation - see README.md -->

# Cache

Part of [what gets recorded](../lenses.md).

![The Cache tab](../images/35-lens-cache-light.webp#gh-light-mode-only)
![The Cache tab](../images/35-lens-cache-dark.webp#gh-dark-mode-only)

Section `cache`: hits, misses, writes, forgets, flushes and failed operations with key, store, tags, TTL, value size and, when the store reports when a call started, duration. A key read again in the same capture points at its first read (`repeat_of`). The summary shows the hit rate and the most used keys.

Warnings: slow cache calls, and keys read over and over - remember such a value for the request with `Cache::memo()` or `once()`.

Options:

- `slow_ms` (default 20) - a cache call taking at least this long gives a warning, wherever it comes from.
- `repeat_threshold` (default 5) and `repeat_ms` (default 0) - a key read at least this often, taking at least this long in all, gives a warning.
- `values` (default false) - also record the cached values.
