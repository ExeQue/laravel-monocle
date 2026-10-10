<!-- Part of the Monocle documentation - see README.md -->

# Artisan commands

Everything the MCP tools do is also available from artisan. The output is JSON; add `--compact` for a single line.

| Command | Same as |
|---|---|
| `monocle:list` | `monocle-list` |
| `monocle:show [id]` | `monocle-show` |
| `monocle:rows <section> [id]` | `monocle-rows` |
| `monocle:queries [id]` | `monocle-queries` |
| `monocle:trace [id]` | `monocle-trace` |
| `monocle:compare <a> [b]` | `monocle-compare` |
| `monocle:clear` | `monocle-clear` |

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

The commands work even when recording is turned off, so earlier captures can always be read.
