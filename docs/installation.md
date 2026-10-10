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
6. **MCP clients** - where to add the `monocle` server. If your project uses [Laravel Boost](https://laravel.com/docs/boost), this defaults to every agent Boost is set up for, in the files and format each agent uses.
7. **Publish** - write `config/monocle.php` with your answers.

| Platform | Command written to the MCP config |
|---|---|
| Laravel Sail | `vendor/bin/sail artisan mcp:start monocle` |
| Lerd | `lerd artisan mcp:start monocle` |
| Laravel Herd | `herd php artisan mcp:start monocle` |
| Plain PHP | `php /path/to/project/artisan mcp:start monocle` |

With file storage, the folder is also kept out of your tools - for the ones the project already uses:

- added to `.gitignore`
- excluded in PhpStorm and Zed
- left out of search and file watching in VS Code, Cursor and Windsurf
- added to `.cursorignore`, `.codeiumignore`, `.codexignore`, `.aiignore` and `.aiexclude`, so AI tools do not read it

`.monocle.json` (your editor settings, see below) is added to `.gitignore` as well.

Restart your MCP client when the command is done. If you use Claude Code with an allow-list in `.claude/settings.local.json`, add `"monocle"` to `enabledMcpjsonServers`.

Running the command again is safe: existing MCP servers and settings in the files are kept.

### Without prompts

Every answer has a flag:

```bash
php artisan monocle:install --no-interaction \
    --platform=sail \
    --file=boost \
    --publish --disk=media --driver=file --limit=200
```

`--file=boost` adds the server to every Laravel Boost agent; any other value is a config file path, e.g. `--file=.mcp.json`. Without `--file`, Boost's agents are used when the project has a `boost.json`, otherwise `.mcp.json`.

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

## Opening files in your editor

```bash
php artisan monocle:editor
```

Pick your editor and file paths and class names in Monocle become links that open them. The answers are saved in `.monocle.json` in the project root. Each developer has their own, so it is kept out of git.

```json
{
    "editor": "phpstorm",
    "paths": { "/var/www/html": "/home/you/code/project" }
}
```

**`editor`** - `phpstorm`, `idea`, `vscode`, `cursor`, `windsurf`, `zed`, `sublime` or `nova`. Any other editor works with a URL template using `{file}` and `{line}`:

```json
{ "editor": "myeditor://open?file={file}&line={line}" }
```

**`paths`** - when PHP runs in a container (Sail, Lerd), the paths it sees differ from the ones on your machine. Map each container path to where it is for your editor. You are asked for this when it applies.

**WSL** - when PHP runs under WSL, you are asked where your editor runs:

| Editor runs | Links open |
|---|---|
| On Windows, project inside WSL | the file in the distro, e.g. `\\wsl.localhost\Ubuntu\home\...`; VS Code, Cursor and Windsurf open it in their WSL window |
| Inside WSL | the Linux path |
| On Windows, project on a Windows drive | the mapped Windows path, e.g. `C:/code/project` |

The distro is saved as `"wsl": { "distro": "Ubuntu" }`. A URL template also gets `{distro}` and `{linux_file}`.

**PhpStorm and IntelliJ IDEA on Windows** - these do not register their own link handler on Windows, so links open through [JetBrains Toolbox](https://www.jetbrains.com/toolbox-app/) instead. You are asked for the project name (detected from `.idea`), saved as `"project"`. The project has to be open in the IDE. Use `--project=` to set it, or `--project=""` to go back to `phpstorm://` links.

Without `.monocle.json`, paths are shown as plain text.

| Flag | Meaning |
|---|---|
| `--editor=` | editor name or URL template |
| `--map=from:to` | a path mapping (repeatable) |
| `--wsl=` | `windows`, `wsl` or `windows-drive` |
| `--distro=` | the WSL distro holding the project |
| `--project=` | JetBrains project name for links through Toolbox |
| `--dry-run` | show what would be written |

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
