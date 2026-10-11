---
name: laravel-monocle
description: Profile Laravel requests, jobs and commands with Monocle - find slow queries, N+1s, cache misses and what a request actually did, then verify a fix.
---

# Laravel Monocle

## When to use this skill

Use this skill when something in the Laravel application is slow, does too much work or behaves unexpectedly, and you want facts instead of guesses: which queries ran and where from, what was cached, which views and Livewire components rendered, which jobs were dispatched, who the request was authenticated as.

Monocle records every request, queued job, artisan command and MCP call while it is enabled. You read the recordings ("captures") through the `monocle` MCP server.

## Workflow

1. **Reproduce** the request, job or command. For an HTTP request, call it (for example with curl) or ask the user to open the page. Call `monocle-clear` first if you want a clean list.
2. **Find the capture** with `monocle-list`. Filter with `uri`, `type`, `status`, `min_duration_ms` or `has_errors`. The newest capture comes first; `monocle-show id=latest` shows the latest one.
3. **Read the summary** with `monocle-show`. It gives the time per phase, totals per lens, warnings Monocle flagged and the sections the capture holds.
4. **Dig into a section** with `monocle-rows id=... section=...`. Use `where`, `sort`, `fields` and `limit` to keep the answer small. `monocle-queries` groups queries by shape, caller, table or transaction.
5. **Follow the chain** with `monocle-trace` when a request dispatched jobs or a job dispatched others.
6. **Fix and verify.** Change the code, reproduce again and compare the two captures with `monocle-compare`.

## Reading the results

- Every row has an `origin`: the file and line in the application that caused it. When the call came through a library, `via` names the library method that was called from that line.
- Warnings are worth acting on first: N+1 queries, slow or duplicate queries, repeated cache calls and repeated views, failed jobs and exceptions, a response served from the response cache.
- Durations are in milliseconds. A view's duration includes the views it rendered.
- Ask only for the sections you need; large captures can hold hundreds of rows.

## Sections

@php
    $lenses = rescue(fn () => app(\ExeQue\Monocle\LensRegistry::class)->descriptions(), [], false);
@endphp
@if ($lenses !== [])
These lenses are enabled in this application. Each key is a section you can pass to `monocle-rows`:

@foreach ($lenses as $key => $description)
- `{!! $key !!}`: {!! $description !!}
@endforeach

`summary` and `timeline` are always available as well. Call `monocle-lenses` for the current list if the configuration has changed since this skill was installed.
@else
Call `monocle-lenses` for the sections this application records. `summary` and `timeline` are always available.
@endif

## Timing your own code

Wrap code you want to see on the timeline in a span:

```php
use ExeQue\Monocle\Facades\Monocle;

$result = Monocle::measure('import photos', fn () => $importer->run($album));

$span = Monocle::span('resize')->start(['width' => 800]);
// ...
$span->stop();
```
