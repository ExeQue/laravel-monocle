<!-- Part of the Monocle documentation - see README.md -->

# Installation

```bash
composer require --dev exeque/laravel-monocle
```

The service provider is auto-discovered. Monocle needs PHP 8.4 and Laravel 13. [`laravel/mcp`](https://laravel.com/docs/mcp) comes with it; `laravel/ai` and `livewire/livewire` are optional and only matter for the lenses that record them.

`monocle:install` (below) publishes a config tailored to your answers; `php artisan vendor:publish --tag=monocle-config` publishes the defaults untouched.

## Enabling Monocle

Monocle records only when it is enabled, and when it is not enabled it hooks nothing: no listeners, no container extensions, no middleware decoration. That is what makes it safe to keep in `require-dev` of an application that is also deployed.

Out of the box it is enabled when both hold:

- the application is **not** in the `production` environment, and
- `app.debug` is `true`.

It is never enabled inside the test runner unless you say so explicitly.

Three ways to override that:

```dotenv
# .env - force on or off
MONOCLE_ENABLED=true
```

```php
// config/monocle.php
'enabled' => env('MONOCLE_ENABLED'),   // null = the default rule above
```

```php
// Any service provider's register() method - decided at runtime
use ExeQue\Monocle\Facades\Monocle;

public function register(): void
{
    Monocle::enabled(fn () => ! $this->app->isProduction() && $this->app->hasDebugModeEnabled());
}
```

The closure is evaluated once, when Monocle boots. A runtime override wins over config; config wins over the default rule.

## Installing

```bash
php artisan monocle:install
```

An interactive form walks through the setup and remembers nothing it does not write down:

1. **Lenses** - every built-in lens, all selected; untick what you do not want recorded.
2. **Disks** - which `Storage` disks the `FilesystemLens` should watch (`All disks` = `*`).
3. **Storage** - `file` (default, shared by web, workers and the MCP server) or `cache`.
4. **Ring buffer size** - how many captures to keep.
5. **Platform** - how your MCP client starts PHP, pre-selected from detection (table below).
6. **MCP clients** - which config files get the `monocle` server: `.mcp.json` (Claude Code, Codex), `.cursor/mcp.json`, `.vscode/mcp.json`, `.ai/mcp/mcp.json` (Boost's source, so `boost:update` keeps it).
7. **Publish** - write `config/monocle.php` with those choices. The shipped config is edited in place, so every comment and option survives; lenses you unticked become `false`.

When the file store is chosen, its directory (`/storage/monocle/`) is added to `.gitignore` with a comment above it.

| Detected | Signal | Command written |
|---|---|---|
| Lerd | `.lerd.yaml` or `.lerd` in the project, or a `LERD_*` environment variable | `lerd php artisan mcp:start monocle` |
| Sail | `vendor/bin/sail` plus a compose file, or `LARAVEL_SAIL=1` | `vendor/bin/sail artisan mcp:start monocle` |
| Herd | Herd installed (`/Applications/Herd.app` or `~/.config/herd`) | `herd php artisan mcp:start monocle` |
| Plain PHP | nothing above | `<php binary> <project>/artisan mcp:start monocle` |

Every answer has a flag, so scripts and CI can run it without prompts:

```bash
php artisan monocle:install --no-interaction \
    --platform=sail \
    --file=.mcp.json --file=.ai/mcp/mcp.json \
    --publish --disk=media --disk='uploads-*' --driver=file --limit=200

php artisan monocle:install --dry-run            # show what would change
```

Without `--publish` a non-interactive run only registers the MCP server. The MCP writer merges: other servers and keys are untouched, the file keeps its indentation, and running it again is a no-op.

Then restart your MCP client. Claude Code users who keep an allow-list in `.claude/settings.local.json` add `"monocle"` to `enabledMcpjsonServers`.

### Manually

If your client is not file based, the server is a plain stdio MCP server:

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

Verify with the MCP inspector:

```bash
php artisan mcp:inspector monocle
```

## Where the data lives

Captures are written to `storage/monocle/` as JSON, newest 200 kept. A `.gitignore` is created in that directory. See [Storage](storage.md) to change the driver or the limit.
