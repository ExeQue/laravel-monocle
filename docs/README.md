<!-- Part of the Monocle documentation - see ../README.md -->

# Documentation

Also published at [exeque.github.io/laravel-monocle](https://exeque.github.io/laravel-monocle/), with navigation and search.

- [Installation](installation.md) - Requiring the package, enabling it safely, registering the MCP server
- [For AI agents](agents.md) - The MCP tools, the workflow an agent follows, response headers and `origin`
- [Artisan commands](commands.md) - The same data from the terminal
- [What gets recorded](lenses.md) - Every built-in lens, its section and its options
- [Spans and metadata](spans.md) - Measuring your own code and attaching metadata
- [Writing a lens](writing-a-lens.md) - Recording data Monocle does not know about
- [Configuration](configuration.md) - Every key in `config/monocle.php`
- [Storage](storage.md) - The ring buffer, file and cache drivers, custom stores

## Where things are

| Looking for | Page |
|---|---|
| `Monocle::enabled()`, production safety | [Installation](installation.md#enabling-monocle) |
| `monocle-list`, `monocle-show`, `monocle-rows`, `monocle-queries`, `monocle-trace` | [For AI agents](agents.md#tools) |
| `X-Monocle-Id`, `Server-Timing`, `X-Monocle-Label` | [For AI agents](agents.md#response-headers) |
| `monocle:install`, platform detection | [Installation](installation.md#registering-the-mcp-server) |
| `Monocle::span()`, `measure()`, `mark()`, `meta()`, `label()` | [Spans and metadata](spans.md) |
| Looking a span up by key | [Spans and metadata](spans.md#handing-a-span-over) |
| `Monocle::register()`, `disable()`, `enable()` | [Configuration](configuration.md#lenses) |
| `Lens`, `Configurable`, `SummarizesEntry` | [Writing a lens](writing-a-lens.md) |
| `Recorder::push()`, `set()`, `span()` | [Writing a lens](writing-a-lens.md#the-recorder) |
| `Monocle::extendStore()`, the `Store` contract | [Storage](storage.md#custom-stores) |
| Masking secrets, `PartialMask` | [Configuration](configuration.md#masking) |
| Phases, the derived controller span | [What gets recorded](lenses.md#requestlens) |
| N+1, duplicate and slow query warnings | [What gets recorded](lenses.md#databaselens) |
