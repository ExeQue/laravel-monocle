<!-- Part of the Monocle documentation - see README.md -->

# Storage

Monocle keeps the most recent captures - 200 by default - and removes older ones automatically. Captures are saved after the response has been sent, so they do not slow your requests down.

```php
'storage' => [
    'driver' => env('MONOCLE_STORAGE', 'file'),
    'limit' => 200,
    'file' => ['path' => storage_path('monocle')],
    'cache' => ['store' => null, 'ttl' => 86400],
],
```

## Files (default)

Captures are stored as JSON files in `storage/monocle`. Requests, queue workers and the MCP server all read and write the same files, with nothing to set up. The folder must be inside your `storage` directory.

`monocle:install` adds the folder to `.gitignore`.

## Cache

Stores captures in a cache store; `store` is the store name, `null` for your default. Use a store your web server, queue workers and MCP server share, like `redis` or `database` - the `array` store will not work.

## Your own store

Implement `ExeQue\Monocle\Contracts\Store`:

```php
interface Store
{
    public function put(Capture $capture): void;   // save, and remove the oldest beyond the limit
    public function find(string $id): ?Capture;
    public function index(): array;                // one summary row per capture, newest first
    public function clear(): void;
}
```

`ExeQue\Monocle\Storage\Index::summarize($capture)` builds the summary row.

Register it in a service provider's `register()` method:

```php
Monocle::extendStore('s3', fn ($app, array $config) => new S3Store(
    $app['filesystem']->disk('s3'),
    (int) $config['limit'],
));
```

```php
'storage' => ['driver' => 's3', 'limit' => 500],
```

## File format

Each capture is a JSON document:

```json
{
  "schema_version": 1,
  "id": "01a126fa-...", "type": "request", "name": "GET /en", "status": "200", "label": null,
  "parent_id": null, "trace_id": "01a126fa-...",
  "started_at": 1760120000.123, "ended_at": 1760120000.592, "duration": 469.5,
  "meta": {},
  "spans": [{"id": "s1", "kind": "phase", "name": "bootstrap", "start": 0, "end": 51.9, "duration": 51.9, "meta": {}}],
  "sections": {"request": {"method": "GET"}, "queries": [{"sql": "select ...", "duration": 1.2, "origin": {"file": "app/...", "line": 27}}]}
}
```
