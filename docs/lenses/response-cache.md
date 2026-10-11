<!-- Part of the Monocle documentation - see README.md -->

# Response cache

Part of [what gets recorded](../lenses.md).

![The Response cache tab](../images/54-lens-response-cache-light.webp#gh-light-mode-only)
![The Response cache tab](../images/54-lens-response-cache-dark.webp#gh-dark-mode-only)

Section `response_cache`: whether the response came from the cache, how old it was and its tags, and when the cache was cleared. A hit gives a warning: the controller did not run, so a change to it shows nothing until the entry expires or the cache is cleared.

Needs `spatie/laravel-responsecache`.
