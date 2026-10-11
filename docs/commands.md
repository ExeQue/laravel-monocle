<!-- Part of the Monocle documentation - see README.md -->

# Artisan commands

Everything the MCP tools do is also available from artisan. Run by a person, the output is formatted for reading: tables, a tree for traces, and warnings and errors in colour. Run by an AI agent (Claude Code, Cursor, Codex, Gemini CLI and others are detected), the output is the same JSON the MCP tools return. Add `--json` (or `--agent`) to get the JSON yourself, and `--compact` for JSON on a single line.

| Command | Same as |
|---|---|
| `monocle:list` | `monocle-list` |
| `monocle:show [id]` | `monocle-show` |
| `monocle:rows <section> [id]` | `monocle-rows` |
| `monocle:queries [id]` | `monocle-queries` |
| `monocle:trace [id]` | `monocle-trace` |
| `monocle:compare <a> [b]` | `monocle-compare` |
| `monocle:clear` | `monocle-clear` |
| `monocle:tui` | |

Without an `id` the latest capture is used.

```bash
php artisan monocle:list --uri=/orders --min-duration-ms=300 --since=15m
php artisan monocle:list --has=exceptions
php artisan monocle:show
php artisan monocle:show --section=timeline --kind=middleware
php artisan monocle:rows queries --where='duration:>:20' --sort=duration --dir=desc --field=sql --field=origin
php artisan monocle:queries --group-by=caller --sort=count
php artisan monocle:trace 01a126fa-6f82-71f5-8387-75067ec20c61
php artisan monocle:compare before
php artisan monocle:clear
```

`--where` takes `field:op:value` and can be repeated. `--verbose-origin` shows the full call chain behind each `origin`.

`monocle:clear` asks before it deletes anything; add `--force` to skip the question.

The commands work even when recording is turned off, so earlier captures can always be read.

## Terminal UI

`monocle:tui` opens a full-screen browser for captures. The list updates as new captures come in; open one to see a tab per section, rendered like the commands above.

| Key | In the list | In a capture |
|---|---|---|
| `j` / `k` or arrows | next / previous capture | scroll |
| `enter` | open the capture | |
| `/` | filter by name, type, status, route, label or tag; `esc` clears it | |
| `u` / `i`, left / right or tab | | previous / next tab |
| `J` / `K` | | next / previous capture, same tab |
| `g` / `G`, Home / End | first / last capture | top / bottom |
| Page Up / Page Down | a page up / down | a page up / down |
| `esc` | | back to the list |
| `q` | quit | quit |
