<!-- Part of the Monocle documentation - see README.md -->

# Spans and metadata

Everything goes through the `Monocle` facade. There is no global helper. The package registers `Monocle` as a global class alias, so `use Monocle;` is all you need; the full class is `ExeQue\Monocle\Facades\Monocle`.

```php
use Monocle;
```

## Measuring your own code

```php
$span = Monocle::span('import photos')->start();
// ... work ...
$span->stop();
```

Spans nest automatically: a span started while another is open becomes its child, whatever code started the parent. Stopping out of order is fine.

```php
$result = Monocle::measure('resize', fn () => $this->resize($file));   // returns the callback's result
Monocle::mark('cache warmed');                                          // zero-length span
```

Names are free. Ten spans named `resize` in a loop are ten spans; nothing is deduplicated or merged.

## Metadata

Spans carry an associative array of metadata. `start()`, `stop()` and `meta()` all accept one and merge it with `array_merge`, so later keys win:

```php
$span = Monocle::span('import photos')->start(['album' => $album->id]);
$span->meta(['source' => 'dropbox']);
$span->stop(['count' => 12]);
$span->meta(['result' => 'ok']);        // still fine after stop(): the span stays mutable until the capture is stored
```

Values are normalized before storage: Eloquent models become `App\Models\Album#12`, enums their value, dates ISO strings, closures and resources a placeholder.

The capture itself takes metadata and a label too:

```php
Monocle::meta(['tenant' => $tenant->id]);   // section "meta" on the capture
Monocle::label('baseline');                 // findable with monocle-list label=baseline
```

## Handing a span over

When the code that stops a span is not the code that started it and passing the object around is awkward, give the span a key:

```php
// In the action
Monocle::span('import photos', key: "import:{$album->id}")->start();

// In a listener, somewhere else entirely
Monocle::span(key: "import:{$album->id}")->stop(['count' => $count]);
```

`Monocle::span(key: ...)` returns the open span with that key if there is one, otherwise a new span (then a name is required). Keys are unique among *open* spans only: once stopped, the key is free again. Without a key there is never a lookup.

Spans never cross a process boundary. A span started in a request cannot be stopped in a queued job; start a new one in the job - the jobs appear in `monocle-trace` anyway.

## When nothing is being recorded

Outside a capture (Monocle disabled, or code running where no request, job or command is being recorded) every call is a no-op: `span()` returns a handle that never starts, `measure()` just runs the callback. No conditionals needed around instrumentation.

Spans that are still open when the capture is stored are closed with `ended_by: finalize` and counted in the summary's warnings, so a forgotten `stop()` is visible rather than silently lost.

## Reading them back

Spans live in the timeline: `monocle-show sections=[timeline]` or `monocle-rows section=timeline`. Your spans have kind `user` (`mark` for marks); filter with `kinds=[user]`.
