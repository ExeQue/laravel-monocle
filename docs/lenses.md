<!-- Part of the Monocle documentation - see README.md -->

# What gets recorded

A **capture** is one request, queued job, artisan command or MCP call. Each capture has a timeline of timed **spans** and a number of **sections** with rows. Each section is filled by a **lens**.

Every row records when it happened and the `origin` - the file and line in your code that caused it.

Turn lenses on, off or change their options in [`config/monocle.php`](configuration.md#lenses).

Lenses report two kinds of problems in the summary: **errors** for things that failed and **warnings** for things worth a look. Each lens's page says which it reports; a response with a 5xx status is an error too. Warnings point at your code. Slow or repeated work that comes from a package on its own - a slow query a library runs, a package's middleware - is not flagged. Possible N+1 queries, slow cache calls and slow processes are flagged wherever they come from.

## At a glance

### Core

Built on Laravel itself; nothing else to install.

| Lens | Section | Records |
|---|---|---|
| [Request](lenses/request.md) | `request` | method, URL, input, headers, cookies, route, response and phases |
| [Session](lenses/session.md) | `session` | session contents and what the request changed |
| [Auth](lenses/auth.md) | `auth` | the user, logins, failed attempts and lockouts |
| [Validation](lenses/validation.md) | `validation` | every validation, its rules and the rules that failed |
| [Middleware](lenses/middleware.md) | `middleware` | each middleware before and after the controller |
| [Database](lenses/database.md) | `queries` | every query, transactions, N+1 and slow queries |
| [Eloquent](lenses/eloquent.md) | `models`, `model_changes` | models loaded and every changed attribute |
| [Cache](lenses/cache.md) | `cache` | hits, misses, writes and repeated reads |
| [Redis](lenses/redis.md) | `redis` | every Redis command |
| [Views](lenses/views.md) | `views` | every view and component with its duration |
| [Events](lenses/events.md) | `events` | your events and their listeners |
| [Queue](lenses/queue.md) | `jobs` | jobs dispatched, and each job as its own capture |
| [Artisan commands](lenses/artisan-commands.md) | `command`, `commands` | arguments, options and exit code; commands called during a capture |
| [Scheduler](lenses/scheduler.md) | `scheduled_tasks` | each scheduled task and its outcome |
| [HTTP client](lenses/http-client.md) | `http` | outgoing calls made with the `Http` client |
| [Mail and notifications](lenses/mail-and-notifications.md) | `mail` | mail and notifications sent |
| [Filesystem](lenses/filesystem.md) | `filesystem` | operations on the `Storage` disks you choose |
| [Processes](lenses/processes.md) | `processes` | external commands run with the `Process` facade |
| [Exceptions](lenses/exceptions.md) | `exceptions` | reported exceptions and where they came from |
| [Log](lenses/log.md) | `log` | everything written to the log |
| [Authorization](lenses/authorization.md) | `authorization` | every gate and policy check |
| [Dumps](lenses/dumps.md) | `dumps` | every `dump()` and `dd()` |

### Packages

These record something only when the package is installed; otherwise they do nothing.

| Package | Lens | Section | Records |
|---|---|---|---|
| [Filament](https://filamentphp.com) | [Filament](lenses/filament.md) | `filament`, `filament_context`, `livewire` | actions and how they ended, notifications, table filters, search and sort, the panel, page and resource |
| [franbarbalopez/mirror](https://github.com/franbarbalopez/mirror) | [Impersonation](lenses/auth.md#impersonation) | `auth` | who is impersonating the user |
| [Inertia](https://inertiajs.com) | [Inertia](lenses/inertia.md) | `inertia`, `inertia_pages` | the page, every prop with its type and size, deferred props and partial reloads |
| [lab404/laravel-impersonate](https://github.com/404labfr/laravel-impersonate) | [Impersonation](lenses/auth.md#impersonation) | `auth` | who is impersonating the user |
| [Lighthouse](https://lighthouse-php.com) | [GraphQL](lenses/graphql.md) | `graphql` | operations with their query, variables, errors and time |
| [Livewire](https://livewire.laravel.com) | [Livewire](lenses/livewire.md) | `livewire` | components, method calls, property changes and render time |
| [Prism](https://prismphp.com) | [Prism](lenses/prism.md) | `prism`, `prism_tools` | text, structured, stream, embeddings, image and audio calls with tokens, and tool calls with their time |
| [rebing/graphql-laravel](https://github.com/rebing/graphql-laravel) | [GraphQL](lenses/graphql.md) | `graphql` | operations with their schema, query, variables, errors and time |
| [stechstudio/filament-impersonate](https://github.com/stechstudio/filament-impersonate) | [Impersonation](lenses/auth.md#impersonation) | `auth` | who is impersonating the user |
| **Laravel** | | | |
| [laravel/ai](https://github.com/laravel/ai) | [AI](lenses/ai.md) | `ai`, `ai_tools` | agents, steps, tool calls with their time, and tokens |
| [laravel/ai](https://github.com/laravel/ai) | part of [HTTP client](lenses/http-client.md), see [AI provider keys](lenses/http-client.md#ai-provider-keys) | `http` | the providers' API key headers, masked |
| [Laravel Folio](https://laravel.com/docs/folio) | part of [Request](lenses/request.md), see [Folio](lenses/request.md#folio) | `request` | the page file a request matched |
| [Laravel Fortify](https://laravel.com/docs/fortify) | [Fortify](lenses/auth.md#fortify) | `auth` | two-factor challenges, failed and passed codes |
| [laravel/mcp](https://github.com/laravel/mcp) | [MCP servers](lenses/mcp-servers.md) | `mcp` | calls to your application's MCP servers |
| [Laravel Octane](https://laravel.com/docs/octane) | part of [Request](lenses/request.md), see [Octane](lenses/request.md#octane) | - | a capture per request in a worker |
| [Laravel Nova](https://nova.laravel.com) | [Impersonation](lenses/auth.md#impersonation) | `auth` | who is impersonating the user |
| [Laravel Pennant](https://laravel.com/docs/pennant) | [Feature flags](lenses/feature-flags.md) | `features` | every feature check and its value |
| [Laravel Sanctum](https://laravel.com/docs/sanctum) | [Sanctum](lenses/auth.md#sanctum) | `auth` | the token a request used |
| **Spatie** | | | |
| [laravel-event-sourcing](https://github.com/spatie/laravel-event-sourcing) | [Event sourcing](lenses/event-sourcing.md) | `event_sourcing`, `aggregates` | events stored, the projectors and reactors that handled them, and aggregate roots retrieved and persisted |
| [laravel-medialibrary](https://github.com/spatie/laravel-medialibrary) | [Media library](lenses/media-library.md) | `media` | media added and conversions run |
| [laravel-responsecache](https://github.com/spatie/laravel-responsecache) | [Response cache](lenses/response-cache.md) | `response_cache` | cache hits and misses for whole responses |
