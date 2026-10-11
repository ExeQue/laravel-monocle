<p align="center"><img src="./art/cover.jpg" alt="Monocle - the Laravel profiler your coding agent reads" width="100%"></p>

<p align="center">
<a href="https://packagist.org/packages/exeque/laravel-monocle"><img src="https://img.shields.io/packagist/v/exeque/laravel-monocle" alt="Latest version"></a>
<a href="https://packagist.org/packages/exeque/laravel-monocle"><img src="https://img.shields.io/packagist/dt/exeque/laravel-monocle" alt="Total downloads"></a>
<a href="https://github.com/ExeQue/laravel-monocle/actions/workflows/tests.yml"><img src="https://github.com/ExeQue/laravel-monocle/actions/workflows/tests.yml/badge.svg?branch=main" alt="Tests"></a>
<a href="https://github.com/ExeQue/laravel-monocle/actions/workflows/docs.yml"><img src="https://github.com/ExeQue/laravel-monocle/actions/workflows/docs.yml/badge.svg?branch=main" alt="Documentation"></a>
<img src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2FExeQue%2Flaravel-monocle%2Fmain%2Fcomposer.json&query=%24.require.php&label=php&color=777bb4" alt="PHP version">
<img src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2FExeQue%2Flaravel-monocle%2Fmain%2Fcomposer.json&query=%24.require%5B%22illuminate%2Fsupport%22%5D&label=laravel&color=ff2d20" alt="Laravel version">
<a href="LICENSE.md"><img src="https://img.shields.io/github/license/ExeQue/laravel-monocle" alt="License"></a>
</p>

# Monocle

**Documentation: [exeque.github.io/laravel-monocle](https://exeque.github.io/laravel-monocle/)**

A request profiler for Laravel, built for AI coding agents. Monocle records what your application did while handling a request, a queued job or an artisan command - timing, middleware, database queries, cache, views, mail, HTTP calls, filesystem operations, AI agent calls and more - and lets an agent read it through an MCP server. The same data is available from artisan.

## Install

```bash
composer require --dev exeque/laravel-monocle
php artisan monocle:install
```

`monocle:install` asks what to record, where to store it and which MCP clients to configure, then writes `config/monocle.php` and registers the MCP server. Restart your MCP client afterwards.

Monocle records only outside production and only with `APP_DEBUG=true`.

## What your agent can do

| Tool | Purpose |
| --- | --- |
| `monocle-list` | Recent captures with duration, status and per-section counts |
| `monocle-show` | Where the time went, totals and warnings (slow queries, N+1, duplicates, slow middleware, failures) |
| `monocle-rows` | Any section, filtered, sorted and paginated |
| `monocle-queries` | Queries grouped by shape, caller, table or transaction |
| `monocle-trace` | A request and every job it dispatched |
| `monocle-compare` | Two captures side by side - did the change help? |
| `monocle-clear` | Start from a clean slate |
| `monocle-lenses` | The sections this application records |

Every recorded response carries an `X-Monocle-Id` header, and every recorded row points to the `file:line` in your code that caused it.

From the terminal: `monocle:list`, `monocle:show`, `monocle:rows`, `monocle:queries`, `monocle:trace`, `monocle:compare`, `monocle:clear`.

## Measure your own code

```php
use Monocle;

$span = Monocle::span('import photos')->start(['album' => $album->id]);
// ...
$span->stop(['count' => $count]);

Monocle::measure('resize', fn () => $this->resize($file));
```

## Learn more

- [Installation](https://exeque.github.io/laravel-monocle/docs/installation)
- [For AI agents](https://exeque.github.io/laravel-monocle/docs/agents)
- [What gets recorded](https://exeque.github.io/laravel-monocle/docs/lenses)
- [Spans and metadata](https://exeque.github.io/laravel-monocle/docs/spans)
- [Writing a lens](https://exeque.github.io/laravel-monocle/docs/writing-a-lens)
- [Third-party lenses](https://exeque.github.io/laravel-monocle/docs/third-party-lenses)
- [Configuration](https://exeque.github.io/laravel-monocle/docs/configuration)

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request. Report security issues privately as described in [SECURITY.md](SECURITY.md). Everyone taking part follows the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

MIT
