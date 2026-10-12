<!-- Part of the Monocle documentation - see ../README.md -->

# Documentation

- [Installation](installation.md) - Install, enable and connect your MCP client
- [For AI agents](agents.md) - The MCP tools and how an agent uses them
- [Artisan commands](commands.md) - The same data from the terminal
- [Dashboard](dashboard.md) - The same data in your browser
- [Tracing](tracing.md) - How a page, its calls, jobs and processes are linked
- [Browser tracing](browser-tracing.md) - Linking a page's fetch and XHR calls, and the Monocle button on the page
- [What gets recorded](lenses.md) - Every lens and what it records
- [Spans and metadata](spans.md) - Measure your own code
- [Writing a lens](writing-a-lens.md) - Record something Monocle does not know about
- [Third-party lenses](third-party-lenses.md) - Lenses for other packages, maintained by others
- [Configuration](configuration.md) - Every option in `config/monocle.php`
- [Storage](storage.md) - Where captures are kept

## Where things are

| Looking for | Page |
|---|---|
| Turning Monocle on or off | [Installation](installation.md#enabling-monocle) |
| `monocle:install` | [Installation](installation.md#installing) |
| `monocle-list`, `monocle-show`, `monocle-rows`, ... | [For AI agents](agents.md#tools) |
| `X-Monocle-Id`, `X-Monocle-Label` | [For AI agents](agents.md#headers) |
| `Monocle::span()`, `measure()`, `mark()`, `meta()`, `label()` | [Spans and metadata](spans.md) |
| Stopping a span somewhere else | [Spans and metadata](spans.md#stopping-a-span-somewhere-else) |
| Turning lenses on, off or adding your own | [Configuration](configuration.md#lenses) |
| Masking secrets | [Configuration](configuration.md#masking) |
| Recording `Storage` disks | [Filesystem](lenses/filesystem.md) |
| Request phases | [Request](lenses/request.md) |
| Linking pages, jobs and processes into a trace | [Tracing](tracing.md) |
| Jumping from a page to its captures | [Browser tracing](browser-tracing.md) |
| N+1 and slow query warnings | [Database](lenses/database.md) |

## Contributing

- [Contributing](../CONTRIBUTING.md) - Proposing a change and what a pull request needs
- [Security](../SECURITY.md) - Reporting a vulnerability
- [Code of Conduct](../CODE_OF_CONDUCT.md)
