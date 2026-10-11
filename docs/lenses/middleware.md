<!-- Part of the Monocle documentation - see README.md -->

# Middleware

Part of [what gets recorded](../lenses.md).

![The Middleware tab](../images/32-lens-middleware-light.webp#gh-light-mode-only)
![The Middleware tab](../images/32-lens-middleware-dark.webp#gh-dark-mode-only)

Section `middleware`: one row per middleware, in the order the request passed through them - the middleware, its group (global or route), its parameters, the time it took before passing the request on and after the response came back, its own time in all, whether it passed the request on and, when it stopped the request (for example a redirect or a 403), with which status. On the timeline each middleware has a `middleware.request` span for the work before and a `middleware.response` span for the work after. Middleware written as closures is not timed.

Warnings: middleware that took at least `slow_ms` of its own time, and middleware that stopped the request.

Option: `slow_ms` (default 50) - middleware slower than this is flagged.

The Middleware tab shows each middleware's time before (`Before`) and after (`After`) the controller and its own time, and can be grouped by group. The overview shows the middleware count and the total middleware time at the top.
