<!-- Part of the Monocle documentation - see README.md -->

# Exceptions

Part of [what gets recorded](../lenses.md).

![The Exceptions tab](../images/38-lens-exceptions-light.webp#gh-light-mode-only)
![The Exceptions tab](../images/38-lens-exceptions-dark.webp#gh-dark-mode-only)

Section `exceptions`: every reported exception with its message, the lines of your code it passed through and the exception that caused it. Each one is reported as an error.

The exception that became the response is marked `unhandled`; the others were reported (with `report()` or `rescue()`) and the request carried on. Each has a `source`: `native` for an exception class built into PHP, `app` for one of your own, or the package that defines it, such as `laravel/framework`. A call through a facade in the stack is named after the facade (`Cache::remember`).

Totals: exceptions, how many were unhandled, how many different classes, and one per source. The tab groups them by exception class or by source.

![An exception folded out](../images/19-exception-details-light.webp#gh-light-mode-only)
![An exception folded out](../images/19-exception-details-dark.webp#gh-dark-mode-only)
