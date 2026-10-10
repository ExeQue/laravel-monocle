<!-- Part of the Monocle documentation - see README.md -->

# Storage

Captures are kept in a **ring buffer**: the newest `monocle.storage.limit` captures (default 200) survive, older ones are evicted on every write. There is no limit on the size of one capture - every query and every span is kept - only on how many captures there are.

Writes happen after the response has been sent (in `terminating`), on job completion and on command completion. A write that fails is logged once and never breaks the application.

## Drivers

```php
'storage' => [
    'driver' => env('MONOCLE_STORAGE', 'file'),
    'limit' => 200,
    'file' => ['path' => storage_path('monocle')],   // must be inside storage_path()
    'cache' => ['store' => null, 'ttl' => 86400],
],
```

### `file` (default)

One `{id}.monocle.json` file per capture plus an `index.monocle.json` of summary rows, in `storage/monocle/`. The store refuses to operate outside the application's `storage_path()` - that boundary is not configurable; a store that must live elsewhere is a [custom store](#custom-stores) and carries that responsibility itself. It also rejects relative paths, only accepts ids made of letters, digits, dashes and underscores, and only deletes files its own index lists that carry the `.monocle.json` suffix - so neither configuration nor a crafted id can make it touch anything else. The directory gets a `.gitignore`. The index is rewritten under an exclusive lock and files are written atomically, so the web process, a queue worker and the MCP server can share it without configuration. Listing, filtering and tracing read only the index.

### `cache`

The same ring buffer on a Laravel cache store (`store` null means `cache.default`), with `ttl` as a safety net. The `array` driver does not share state between processes and therefore cannot serve the MCP server; use `file`, `redis` or `database`.

## Custom stores

Implement the contract:

```php
use ExeQue\Monocle\Contracts\Store;
use ExeQue\Monocle\Data\Capture;

interface Store
{
    public function put(Capture $capture): void;     // persist and trim to the limit
    public function find(string $id): ?Capture;
    public function index(): array;              // summary rows, newest first
    public function clear(): void;
}
```

`ExeQue\Monocle\Storage\Index::summarize($capture)` builds the summary row the readers expect. Then either point `driver` at the class name (it is resolved through the container) or register a named driver:

```php
use Monocle;

Monocle::extendStore('redis-stream', fn ($app, array $config) => new RedisStreamStore(
    $app['redis']->connection(),
    (int) $config['limit'],
));
```

```php
'storage' => ['driver' => 'redis-stream', 'limit' => 500],
```

## The capture format

`Capture::toArray()` is what gets stored and what every reader consumes. It carries `schema_version` (currently 1); changes are additive and a breaking change bumps it. The shape:

```json
{
  "schema_version": 1,
  "id": "01a126fa-...", "type": "request", "name": "GET /en", "status": "200", "label": null,
  "parent_id": null, "trace_id": "01a126fa-...",
  "started_at": 1760120000.123, "ended_at": 1760120000.592, "ended_by": "normal", "duration": 469.5,
  "meta": {}, "health": {"overhead_ms": 7.6, "open_spans_at_finalize": 0},
  "spans": [{"id": "s1", "parent_id": null, "kind": "phase", "name": "bootstrap", "start": 0, "end": 51.9, "duration": 51.9, "meta": {}, "origin": null}],
  "sections": {"request": {"method": "GET"}, "queries": [{"n": 1, "sql": "select ...", "duration": 1.2, "time": 80.1, "origin": {"file": "app/...", "line": 27}}]}
}
```

Because the file store is plain JSON, other tools can read `storage/monocle/*.monocle.json` directly.
