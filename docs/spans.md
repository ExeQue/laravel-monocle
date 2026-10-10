<!-- Part of the Monocle documentation - see README.md -->

# Spans and metadata

Add your own timings and information to captures through the `Monocle` facade:

```php
use Monocle;
```

## Measuring your own code

```php
$span = Monocle::span('import photos')->start();
// ...
$span->stop();
```

A span started while another span is running is shown inside it in the timeline.

```php
$result = Monocle::measure('resize', fn () => $this->resize($file));   // returns the callback's result
Monocle::mark('cache warmed');                                          // a single point in time
```

You can use the same name as often as you like - ten spans called `resize` show up as ten spans.

## Metadata

`start()`, `stop()` and `meta()` all accept an array of metadata. Later values win:

```php
$span = Monocle::span('import photos')->start(['album' => $album->id]);
$span->meta(['source' => 'dropbox']);
$span->stop(['count' => 12]);
$span->meta(['result' => 'ok']);   // also allowed after stop()
```

Models are shown as `App\Models\Album#12`.

Add metadata or a label to the capture itself:

```php
Monocle::meta(['tenant' => $tenant->id]);
Monocle::label('baseline');   // find it again with monocle-list label=baseline
```

## Stopping a span somewhere else

When a span starts in one place and ends in another, give it a key:

```php
// In an action
Monocle::span('import photos', key: "import:{$album->id}")->start();

// In a listener
Monocle::span(key: "import:{$album->id}")->stop(['count' => $count]);
```

A key refers to the running span with that key. Once that span is stopped, the key can be used again.

A span cannot continue in a queued job. Start a new one in the job; `monocle-trace` shows the job next to the request that dispatched it.

## When Monocle is not recording

All of these calls are safe when Monocle is off: spans do nothing and `measure()` simply runs the callback.

A span you forget to stop is closed when the capture ends and shows up as a warning.

## Reading them back

Your spans are in the timeline: `monocle-show sections=["timeline"]`, filtered with `kinds=["user"]`.
