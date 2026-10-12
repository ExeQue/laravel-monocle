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
2. **Find the capture.** Every recorded response carries its capture id in the `X-Monocle-Id` header, so a curl with `-i` gives it to you directly. Otherwise use `monocle-list`, newest first: filter with `uri`, `type`, `status`, `min_duration_ms`, `since`, `has_errors`, `has` (captures where a section recorded rows, e.g. `["exceptions"]`), `exclude` (noise such as `["livewire.update"]`) or `search` (free text, every word must match). `id=latest` works wherever an id is asked for.
3. **Read the summary** with `monocle-show`. It gives the time per phase, totals per lens, the errors and warnings Monocle flagged and the sections the capture holds. Pass `sections` to include rows of a few sections in the same call, and `search` to keep only rows that mention something.
4. **Dig into a section** with `monocle-rows id=... section=...`. Use `where`, `sort`, `fields`, `search` and `limit`/`offset` to keep the answer small. For the timeline, `kinds` picks span kinds (`phase`, `middleware`, `controller`, `user`, `db.transaction`, ...). `monocle-queries` groups queries by shape, caller, table or transaction.
5. **Follow the chain** with `monocle-trace` when a request dispatched jobs, a job dispatched others, a command started a process or a page made calls. Each child says how it was linked (`linked_via`: `queue`, `browser`, `navigation`, `process`); queued jobs no worker has run yet are listed as pending.
6. **Fix and verify.** Label the first run (send `X-Monocle-Label: before`, or call `Monocle::label()`), change the code, reproduce again and compare with `monocle-compare a=before`. It shows deltas of every total and phase, query shapes that appeared or disappeared, and problems unique to each side.

Without the MCP server, the same reads are artisan commands: `monocle:list`, `monocle:show`, `monocle:rows`, `monocle:queries`, `monocle:trace`, `monocle:compare` and `monocle:clear`. They print JSON when run by an agent, or with `--json`.

## Reading the results

- Every row has an `origin`: the file and line in the application that caused it. When the call came through a library, `via` names the library method that was called from that line.
- Errors come first, then warnings; both are worth acting on: exceptions, failed jobs, commands and HTTP calls, error logs, N+1 queries, slow or duplicate queries, repeated cache calls and views, slow middleware, a response served from the response cache.
- Durations are in milliseconds. A view's duration includes the views it rendered.
- Secrets are masked before they are stored: passwords, tokens, authorization headers, sensitive cookies and the configured keys. A masked value is not a bug in the application.
- A capture whose summary has `missing_parent_id` was asked to join a trace whose parent this store no longer holds.
- Ask only for the sections you need; large captures can hold hundreds of rows.

## Sections

@php
    $lenses = rescue(fn () => app(\ExeQue\Monocle\LensRegistry::class)->advice(), [], false);
@endphp
@if ($lenses !== [])
These lenses are enabled in this application. Each key is a section you can pass to `monocle-rows`; `monocle-lenses` lists the fields each one records.

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

Monocle::mark('cache warmed', ['keys' => 3]);  // a point in time
Monocle::meta(['album' => $album->id]);        // facts about the whole capture
```

Spans show up in the `timeline` with kind `user`. Remove them again when the investigation is done, unless the team wants to keep them.

## Create your own lens

When the application has work no lens records (a payment gateway, a search client, an internal service), a lens of its own puts it in a section that every tool above can read, with totals and warnings in the summary. See [Writing a lens](https://exeque.github.io/laravel-monocle/docs/writing-a-lens) for how to build one.

## Documentation

- [What gets recorded](https://exeque.github.io/laravel-monocle/docs/lenses): every lens, the section it writes and its options.
- [For AI agents](https://exeque.github.io/laravel-monocle/docs/agents): every MCP tool and its arguments.
- [Tracing](https://exeque.github.io/laravel-monocle/docs/tracing): how requests, jobs, processes and page calls are linked.
- [Writing a lens](https://exeque.github.io/laravel-monocle/docs/writing-a-lens): recording what no lens covers yet.
