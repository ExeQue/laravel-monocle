<!-- Part of the Monocle documentation - see README.md -->

# Installation

Monocle needs PHP 8.4 and Laravel 13.

```bash
composer require --dev exeque/laravel-monocle
php artisan monocle:install
```

## Installing

`monocle:install` walks you through the setup:

1. **Lenses** - what to record. Everything is selected; untick what you do not need.
2. **Disks** - which `Storage` disks to record operations on.
3. **Storage** - keep captures as files in `storage/monocle` (recommended) or in your cache.
4. **How many captures to keep** - older ones are removed automatically.
5. **Platform** - how your MCP client starts PHP. Detected for you.
6. **MCP clients** - which config files to add the `monocle` server to.
7. **Publish** - write `config/monocle.php` with your answers.

| Platform | Command written to the MCP config |
|---|---|
| Laravel Sail | `vendor/bin/sail artisan mcp:start monocle` |
| Lerd | `lerd artisan mcp:start monocle` |
| Laravel Herd | `herd php artisan mcp:start monocle` |
| Plain PHP | `php /path/to/project/artisan mcp:start monocle` |

Restart your MCP client when the command is done. If you use Claude Code with an allow-list in `.claude/settings.local.json`, add `"monocle"` to `enabledMcpjsonServers`.

Running the command again is safe: existing MCP servers and settings in the files are kept.

### Without prompts

Every answer has a flag:

```bash
php artisan monocle:install --no-interaction \
    --platform=sail \
    --file=.mcp.json \
    --publish --disk=media --driver=file --limit=200
```

Use `--dry-run` to see what would be written.

### Other MCP clients

Any client that can start a local (stdio) MCP server works:

```json
{
    "mcpServers": {
        "monocle": {
            "command": "php",
            "args": ["artisan", "mcp:start", "monocle"]
        }
    }
}
```

To check the server, run `php artisan mcp:inspector monocle`.

## Enabling Monocle

By default Monocle records when the application is **not** in production **and** `APP_DEBUG` is `true`. When it is not recording it does nothing at all, so it is safe to keep installed.

Force it on or off in `.env`:

```dotenv
MONOCLE_ENABLED=true
```

Or decide in a service provider's `register()` method:

```php
use Monocle;

public function register(): void
{
    Monocle::enabled(fn () => $this->app->isLocal());
}
```

Monocle does not record while your test suite runs unless you enable it explicitly.
