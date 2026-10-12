<!-- Part of the Monocle documentation - see README.md -->

# Browser tracing

A page rarely ends with its first response. Livewire updates, Inertia visits and your own `fetch()` calls each reach the server as a request of their own. Browser tracing links those requests to the page that made them, so the page and everything it called form one [trace](tracing.md), and puts a button on the page that takes you to them in the dashboard.

![A page with the Monocle button](images/60-browser-button-light.webp#gh-light-mode-only)
![A page with the Monocle button](images/60-browser-button-dark.webp#gh-dark-mode-only)

## Turning it on

Off by default. Turn it on with [`trace.browser`](configuration.md#trace):

```php
'trace' => [
    'browser' => true,
],
```

Every HTML page Monocle records then gets a small inline script just before `</body>`. Pages without `</body>` get it at the end. Responses that are not `text/html` (JSON, downloads, streamed responses) are left alone.

## What gets linked

The script adds an `X-Monocle-Parent` header, holding the capture id of the page, to the `fetch()` and `XMLHttpRequest` calls the page makes to its own origin. A request carrying that header becomes a child of the page, linked *via browser*. Livewire updates, Inertia visits, your own `fetch()` calls and anything else that goes through `fetch` or `XMLHttpRequest` are linked this way. Calls to other origins are left alone, so the header never leaves your application.

The header is only trusted when it holds a capture id, and only linked when the capture it names is in this application's Monocle store. When it is not there, the request is recorded as a capture of its own, with a note in its header: *Asked to link to `<id>`, not found*. Agents see the same id as `missing_parent_id` in the summary.

![The trace of a page and a Livewire update](images/62-browser-trace-light.webp#gh-light-mode-only)
![The trace of a page and a Livewire update](images/62-browser-trace-dark.webp#gh-dark-mode-only)

### Pages that change without a reload

Livewire's `wire:navigate`, Inertia and other SPA routers fetch the next page from the server and swap it in. The script follows them in any framework: once the address changes, the page fetched for that address becomes the current page, and later calls link to it. Such a fetch is linked *via navigation*. The trace then reads: the first page, its calls, the next page, its calls, and so on.

## The Monocle button

With the dashboard on, the page also gets a round Monocle button in the bottom right corner.

- With only the page's own capture, a click opens it in the dashboard in a new tab.
- Once the page has made calls, the button shows how many captures it holds, and a click lists them: the page and every recorded call, newest first, with method, path and status. Pick one to open it. The list has no limit; it scrolls.
- Drag the button to another corner or the middle of an edge if it is in the way. It snaps to the nearest one, and the spot is remembered in the browser.
- `Esc` or a click outside closes the list.

![The list of captures behind the button](images/61-browser-button-list-light.webp#gh-light-mode-only)
![The list of captures behind the button](images/61-browser-button-list-dark.webp#gh-dark-mode-only)

The button lives in a shadow root on `<html>`, so the page's CSS does not reach it, and routers that replace `<body>` leave it in place. It follows the system's light or dark theme.

## The console

The browser's console shows what the script does: a line when it starts linking the page, a line when the page changes, and a line for every call Monocle recorded, such as `POST /livewire/update recorded as ...8a3670d4, linked to page ...26c79c9a`, each with a link to the capture in the dashboard. Calls Monocle does not record (excepted paths, other origins) are not logged, so a tool that posts console output back to the server cannot set off a loop.

## Content Security Policy

A policy that forbids inline scripts blocks the script: nothing is linked, no button is shown and the page works as before. When your application sets a nonce through Laravel's `Vite::useCspNonce()`, the script carries it and runs.

## What is not linked

- **Full page loads.** Following a link, submitting a form or reloading the page is a new page in the browser, not a call made by the page. It starts a trace of its own.
- **Calls to other origins**, and calls made without `fetch` or `XMLHttpRequest` (images, stylesheets, `<script src>`, `navigator.sendBeacon()`, WebSockets).
- **Client-side route changes** that fetch nothing from the server keep linking to the last page that was fetched.
- **Early calls.** A call the page makes before the page's own capture is stored gets the *not found* note.
- **Pages Monocle does not record** (`except.paths`, the dashboard) get no script.
