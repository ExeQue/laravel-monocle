<?php

declare(strict_types=1);

namespace ExeQue\Monocle;

use Closure;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Reader\SummaryBuilder;
use ExeQue\Monocle\Storage\StoreManager;
use ExeQue\Monocle\Support\Masker;
use ExeQue\Monocle\Support\Masks\PartialMask;
use ExeQue\Monocle\Support\Normalizer;
use ExeQue\Monocle\Support\Origin;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Throwable;

/**
 * Holds the capture currently being recorded and the stack of open spans.
 * Lenses write through push()/set()/span(); nothing here may ever throw into
 * the application, so every write is wrapped and failures are logged once.
 */
final class Eyepiece
{
    private ?Capture $capture = null;

    /** @var list<string> Ids of open spans, innermost last. */
    private array $stack = [];

    /** @var array<string, Span> Spans created with a key that have not been stopped. */
    private array $keyed = [];

    private int $spanCounter = 0;

    private int $overheadNs = 0;

    private bool $discarded = false;

    private bool $finalizing = false;

    /** @var array<string, true> failures already logged in this process */
    private array $loggedFailures = [];

    /** @var list<array{exception: string, message: string, file: string}> failures while recording this capture */
    private array $failures = [];

    /** @var bool|Closure(): bool|null */
    private bool|Closure|null $enabledOverride = null;

    public function __construct(
        private readonly Application $app,
        private readonly StoreManager $stores,
    ) {
    }

    /**
     * Runtime override of the `lens.enabled` config, set through Monocle::enabled().
     *
     * @param  bool|Closure(): bool  $enabled
     */
    public function setEnabled(bool|Closure $enabled): void
    {
        $this->enabledOverride = $enabled;
    }

    public function enabled(): bool
    {
        if ($this->enabledOverride !== null) {
            return (bool) value($this->enabledOverride);
        }

        $enabled = $this->config('enabled');

        if ($enabled !== null && $enabled !== '') {
            return filter_var($enabled, FILTER_VALIDATE_BOOL);
        }

        if ($this->app->runningUnitTests()) {
            return false;
        }

        if ($this->app->isProduction()) {
            return false;
        }

        return (bool) $this->app->make('config')->get('app.debug', false);
    }

    public function masker(): Masker
    {
        $using = $this->config('mask.using', PartialMask::class);

        return new Masker(
            headers: array_map('strtolower', (array) $this->config('mask.headers', [])),
            pattern: $this->config('mask.inputs'),
            mask: is_callable($using) ? $using : $this->app->make($using),
        );
    }

    public function start(CaptureType $type, ?float $startedAt = null, ?string $parentId = null, ?string $traceId = null): Capture
    {
        $this->reset();

        return $this->capture = new Capture(
            id: (string) Str::uuid7(),
            type: $type,
            startedAt: $startedAt ?? microtime(true),
            parentId: $parentId,
            traceId: $traceId,
        );
    }

    public function active(): ?Capture
    {
        return $this->capture;
    }

    public function recording(): bool
    {
        return $this->capture !== null && ! $this->discarded;
    }

    /**
     * Drop the current capture instead of storing it.
     */
    public function discard(): void
    {
        $this->discarded = true;
    }

    /**
     * Milliseconds since the active capture started.
     */
    public function now(): float
    {
        if ($this->capture === null) {
            return 0.0;
        }

        return round((microtime(true) - $this->capture->startedAt) * 1000, 3);
    }

    /**
     * Convert an absolute microtime() to milliseconds relative to the capture.
     */
    public function relative(float $microtime): float
    {
        if ($this->capture === null) {
            return 0.0;
        }

        return round(($microtime - $this->capture->startedAt) * 1000, 3);
    }

    /**
     * Register an event listener whose failures are swallowed and logged, so a
     * bug in a lens never reaches the application.
     *
     * @param  class-string|string  $event
     */
    public function listen(string $event, Closure $handler): void
    {
        $this->app->make('events')->listen($event, function (mixed ...$arguments) use ($handler): void {
            try {
                $handler(...$arguments);
            } catch (Throwable $e) {
                $this->reportFailure($e);
            }
        });
    }

    /**
     * The innermost open span, if any.
     */
    public function currentSpan(): ?Span
    {
        if ($this->capture === null || $this->stack === []) {
            return null;
        }

        return $this->capture->spans[end($this->stack)] ?? null;
    }

    /**
     * Create a span handle. Names are free; pass a key to look up an open span
     * from elsewhere without holding the object.
     */
    public function span(?string $name = null, string $kind = 'user', ?string $key = null): Span
    {
        if ($key !== null && isset($this->keyed[$key])) {
            return $this->keyed[$key];
        }

        $span = new Span(
            id: 's'.++$this->spanCounter,
            name: $name ?? $key ?? 'span',
            kind: $kind,
            parentId: null,
            key: $key,
            eyepiece: $this->capture === null ? null : $this,
        );

        if ($key !== null && $this->capture !== null) {
            $this->keyed[$key] = $span;
        }

        return $span;
    }

    /**
     * @internal Called by Span::start().
     */
    public function startSpan(Span $span, bool $origin = true): void
    {
        $this->measure(function () use ($span, $origin): void {
            if ($this->capture === null) {
                return;
            }

            $span->start = $this->now();
            $span->parentId ??= $this->stack === [] ? null : end($this->stack);

            if ($origin && $span->origin === null) {
                $span->origin = $this->origin();
            }

            $this->capture->spans[$span->id] = $span;
            $this->stack[] = $span->id;
        });
    }

    /**
     * @internal Called by Span::stop().
     */
    public function stopSpan(Span $span, string $endedBy = 'stop'): void
    {
        $this->measure(function () use ($span, $endedBy): void {
            $span->end = $this->now();
            $span->endedBy = $endedBy;

            $this->stack = array_values(array_filter($this->stack, fn (string $id): bool => $id !== $span->id));

            if ($span->key !== null) {
                unset($this->keyed[$span->key]);
            }
        });
    }

    /**
     * Append a row to a section. The user-land origin is attached unless the
     * lens supplied one or opted out. Rows are pushed once the operation has
     * finished, so a row with a duration gets the time it started.
     *
     * @param  array<string, mixed>  $row
     */
    public function push(string $section, array $row, bool $origin = true): void
    {
        $this->measure(function () use ($section, $row, $origin): void {
            if ($this->capture === null) {
                return;
            }

            $row['time'] ??= round($this->now() - (is_numeric($row['duration'] ?? null) ? (float) $row['duration'] : 0.0), 3);

            if ($origin && ! array_key_exists('origin', $row)) {
                $row['origin'] = $this->origin();
            }

            $this->capture->sections[$section][] = Normalizer::normalize($row);
        });
    }

    /**
     * Replace a section with an associative array (for one-off data).
     *
     * @param  array<string, mixed>  $data
     */
    public function set(string $section, array $data): void
    {
        $this->measure(function () use ($section, $data): void {
            if ($this->capture === null) {
                return;
            }

            $existing = $this->capture->sections[$section] ?? [];
            $existing = array_is_list($existing) ? [] : $existing;

            $this->capture->sections[$section] = array_merge($existing, Normalizer::normalize($data));
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): void
    {
        if ($this->capture === null) {
            return;
        }

        $this->capture->meta = array_merge($this->capture->meta, Normalizer::normalize($meta));
    }

    public function describe(?string $name = null, ?string $status = null, ?string $label = null): void
    {
        if ($this->capture === null) {
            return;
        }

        $this->capture->name = $name ?? $this->capture->name;
        $this->capture->status = $status ?? $this->capture->status;
        $this->capture->label = $label ?? $this->capture->label;
    }

    /**
     * Close the capture, let lenses flush, store it and reset. Returns the stored
     * capture or null when nothing was recorded or the capture was discarded.
     */
    public function finalize(string $endedBy = 'normal'): ?Capture
    {
        if ($this->capture === null || $this->finalizing) {
            return null;
        }

        $this->finalizing = true;
        $capture = $this->capture;

        try {
            if ($this->discarded) {
                return null;
            }

            $capture->endedAt = microtime(true);
            $capture->endedBy = $endedBy;

            // Lenses close their own spans first; whatever is still open was forgotten.
            $this->app->make(LensRegistry::class)->finalize($capture);

            foreach ($capture->spans as $span) {
                if ($span->isOpen()) {
                    $this->stopSpan($span, 'finalize');
                }
            }

            $capture->health = [
                'memory_peak_bytes' => memory_get_peak_usage(true),
                'overhead_ms' => round($this->overheadNs / 1_000_000, 3),
                'open_spans_at_finalize' => count(array_filter($capture->spans, fn (Span $span): bool => $span->endedBy === 'finalize')),
                'failures' => $this->failures,
            ];
            $capture->health['warnings'] = count($this->app->make(SummaryBuilder::class)->build($capture, children: false)['warnings']);

            $this->stores->store()->put($capture);

            return $capture;
        } catch (Throwable $e) {
            $this->reportFailure($e);

            return null;
        } finally {
            $this->reset();
            $this->finalizing = false;
        }
    }

    public function reset(): void
    {
        $this->capture = null;
        $this->stack = [];
        $this->keyed = [];
        $this->spanCounter = 0;
        $this->overheadNs = 0;
        $this->discarded = false;
        $this->failures = [];

        if ($this->app->bound(LensRegistry::class)) {
            $this->app->make(LensRegistry::class)->reset();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function origin(): ?array
    {
        if (! $this->config('origin.enabled', true)) {
            return null;
        }

        return Origin::capture(
            basePath: $this->app->basePath(),
            frames: (int) $this->config('origin.frames', 5),
            includeVendor: (array) $this->config('origin.include_vendor', []),
        );
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get('monocle.'.$key, $default);
    }

    private function measure(Closure $work): void
    {
        $started = hrtime(true);

        try {
            $work();
        } catch (Throwable $e) {
            $this->reportFailure($e);
        } finally {
            $this->overheadNs += hrtime(true) - $started;
        }
    }

    /**
     * A lens failed. The application carries on; the failure is kept on the
     * capture and logged once per distinct failure. With throw_failures on
     * (Monocle's own tests) it is thrown instead.
     */
    private function reportFailure(Throwable $e): void
    {
        if ($this->config('throw_failures', false)) {
            throw $e;
        }

        $failure = ['exception' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()];

        if ($this->capture !== null && ! in_array($failure, $this->failures, true)) {
            $this->failures[] = $failure;
        }

        $key = $failure['exception'].'@'.$failure['file'];

        if (isset($this->loggedFailures[$key])) {
            return;
        }

        $this->loggedFailures[$key] = true;

        try {
            $this->app->make('log')->warning('Monocle failed to record: '.$e->getMessage(), $failure);
        } catch (Throwable) {
            // Logging itself is unavailable; stay silent rather than break the app.
        }
    }
}
