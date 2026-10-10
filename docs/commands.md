<!-- Part of the Monocle documentation - see README.md -->

# Artisan commands

Every MCP tool has an artisan twin that prints the same JSON, so the data is available in a terminal, in CI output, or to an agent that runs shell commands rather than MCP. Output is pretty-printed; `--compact` prints one line.

| Command | MCP tool |
|---|---|
| `monocle:list` | `monocle-list` |
| `monocle:show [id]` | `monocle-show` |
| `monocle:rows <section> [id]` | `monocle-rows` |
| `monocle:queries [id]` | `monocle-queries` |
| `monocle:trace [id]` | `monocle-trace` |
| `monocle:clear` | `monocle-clear` |
| `monocle:install` | - |

`id` defaults to `latest` everywhere.

```bash
php artisan monocle:list --uri=/orders --min-duration-ms=300 --since=15m
php artisan monocle:list --has=exceptions --has=queries   # captures where those lenses recorded rows
php artisan monocle:show                       # summary of the latest capture
php artisan monocle:show --section=timeline --kind=middleware
php artisan monocle:show --section=queries --section=cache --limit=10
php artisan monocle:rows queries --where='duration:>:20' --sort=duration --dir=desc --field=sql --field=origin
php artisan monocle:rows timeline --kind=phase
php artisan monocle:queries --group-by=caller --sort=count
php artisan monocle:trace 01a126fa-6f82-71f5-8387-75067ec20c61
php artisan monocle:clear
```

`--where` takes `field:op:value` and is repeatable. `--verbose-origin` includes the full call chain (plain `--verbose` is taken by artisan). A missing capture exits with code 1 and `{"error": "..."}`.

The commands are registered regardless of whether recording is enabled, so you can read captures recorded earlier even after turning Monocle off.
