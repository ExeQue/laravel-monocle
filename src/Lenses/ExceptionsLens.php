<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\ThrowableChain;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Facade;
use ReflectionClass;
use SplFileObject;
use Throwable;

/**
 * Exceptions reported through the exception handler, bound to the capture they
 * happened in, with the user-land frames of their trace. The one that became
 * the response is marked unhandled.
 */
final class ExceptionsLens implements Advises, Lens, Presentable, Resettable, Summarizable
{
    private string $basePath = '';

    /** @var array<int, int> spl_object_id of a recorded exception => its row */
    private array $rows = [];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'exceptions';
    }

    public function description(): string
    {
        return 'Exceptions reported during the capture: class, message, code, file:line, the user-land frames of the stack trace, the previous exception, source (app, native for classes built into PHP, or the composer package that defines the class) and unhandled (true on the exception that became the response; the others were reported and the request carried on). Unreported exceptions (404s and other ignored ones) do not appear; the response status does.';
    }

    public function advice(): string
    {
        return 'Look here first when something went wrong: the exception, where it was thrown and the frames in the application that led to it.';
    }

    public function boot(Application $app): void
    {
        $this->basePath = rtrim($app->basePath(), '/').'/';

        $handler = $this->eyepiece->outside(fn () => $app->make(ExceptionHandler::class));

        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $record = $this->eyepiece->wrap(function (Throwable $exception): void {
            if (! $this->eyepiece->recording()) {
                return;
            }

            $this->rows[spl_object_id($exception)] = count($this->eyepiece->active()?->sections['exceptions'] ?? []);
            $this->eyepiece->push('exceptions', $this->describe($exception) + ['unhandled' => false], origin: false);
        });

        // reportable() reads the type of the closure's parameter, so the timed handler is called from a typed one.
        $handler->reportable(function (Throwable $exception) use ($record): void {
            $record($exception);
        });

        // The handler reports an exception before rendering it, so the response says which one it was.
        $this->eyepiece->listen(RequestHandled::class, function (RequestHandled $event): void {
            $this->markUnhandled($event->response->exception ?? null);
        });
    }

    public function reset(): void
    {
        $this->rows = [];
    }

    /**
     * ponytail: HTTP only. A failed job or command already says so in its own section and status.
     */
    private function markUnhandled(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $row = $this->rows[spl_object_id($exception)] ?? null;

        if ($row === null) {
            return;
        }

        // The rows are forgotten whenever a capture ends, so a known row belongs to the active one.
        $this->eyepiece->active()->sections['exceptions'][$row]['unhandled'] = true;
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('exceptions');

        if ($rows === []) {
            return Summary::make();
        }

        $errors = [];

        foreach (array_slice($rows, 0, 3) as $row) {
            $errors[] = sprintf('%s: %s (%s)', $row['class'], $row['message'], $row['file']);
        }

        $distinct = count(array_unique(array_column($rows, 'class')));

        $summary = Summary::make()
            ->total('exceptions', count($rows), 'exceptions')
            ->total('exceptions_unhandled', count(array_filter(array_column($rows, 'unhandled'))), 'unhandled')
            // One of each says nothing the count does not.
            ->total('exceptions_distinct', $distinct < count($rows) ? $distinct : 0, 'distinct');

        // One total per source, so each package that threw gets its own: PHP's own first, then the app, then packages.
        $sources = array_count_values(array_column($rows, 'source'));
        uksort($sources, fn (string $a, string $b): int => [$this->sourceRank($a), $a] <=> [$this->sourceRank($b), $b]);

        foreach ($sources as $source => $count) {
            $summary = $summary->total('exceptions_from_'.$source, $count, (string) $source);
        }

        return $summary->errors($errors);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Throwable $exception): array
    {
        $chain = ThrowableChain::describe($exception, $this->relative(...));

        return [
            'class' => $chain['class'],
            'message' => $chain['message'],
            'code' => $exception->getCode(),
            'file' => $chain['file'],
            'frames' => $this->frames($exception),
            'previous' => $chain['previous'],
            'source' => $this->source($exception),
        ];
    }

    private function sourceRank(string $source): int
    {
        return match ($source) {
            'native' => 0,
            'app' => 1,
            default => 2,
        };
    }

    /**
     * Who defined the exception's class: PHP itself (native), the application,
     * or the composer package it lives in (vendor/guzzlehttp/guzzle/... is
     * guzzlehttp/guzzle).
     */
    private function source(Throwable $exception): string
    {
        $class = new ReflectionClass($exception);

        if ($class->isInternal()) {
            return 'native';
        }

        if (preg_match('#/vendor/([^/]+/[^/]+)/#', (string) $class->getFileName(), $match) === 1) {
            return $match[1];
        }

        return 'app';
    }

    /**
     * @return list<string>
     */
    private function frames(Throwable $exception): array
    {
        $frames = [];

        $trace = $exception->getTrace();

        foreach ($trace as $index => $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            // Only the application's own frames: vendor frames rarely say where it went wrong.
            if (str_contains($file, '/vendor/')) {
                continue;
            }

            // Nor Monocle's own wrappers (timed middleware and the like), wherever the package lives.
            if (str_starts_with($file, dirname(__DIR__).'/')) {
                continue;
            }

            $line = $frame['line'] ?? '?';
            $frames[] = rtrim($this->relative($file).':'.$line.' '.$this->call($frame, $trace[$index - 1] ?? null));

            if (count($frames) >= 10) {
                break;
            }
        }

        return $frames;
    }

    /**
     * Class::method, Class->method or a plain function name.
     *
     * @param  array<string, mixed>  $frame
     * @param  array<string, mixed>|null  $callee  the frame it called into
     */
    private function call(array $frame, ?array $callee): string
    {
        if (! isset($frame['class'])) {
            return $frame['function'] ?? '';
        }

        // A call into Monocle is the app calling something Monocle wrapped (a middleware's $next); its name says nothing about the app.
        if (str_starts_with($frame['class'], 'ExeQue\\Monocle\\')) {
            return '';
        }

        if ($frame['class'] === Facade::class && $frame['function'] === '__callStatic') {
            return $this->facadeCall($frame, $callee) ?? 'Facade::__callStatic';
        }

        return $frame['class'].($frame['type'] ?? '::').$frame['function'];
    }

    /**
     * The trace says "Facade::__callStatic". The frame it called into names the
     * instance behind the facade and the method (CacheManager->__call with the method as
     * its first argument when proxied); the facade itself is not in the trace, so it is
     * read from the line that made the call when that line calls the same method
     * (Cache::remember). Otherwise the instance is shown (CacheManager->remember).
     *
     * @param  array<string, mixed>  $frame
     * @param  array<string, mixed>|null  $callee
     */
    private function facadeCall(array $frame, ?array $callee): ?string
    {
        // Arguments are left out of traces when zend.exception_ignore_args is on (production default).
        $method = in_array($callee['function'] ?? null, ['__call', '__callStatic'], true)
            ? ($callee['args'][0] ?? null)
            : ($callee['function'] ?? null);

        if (! is_string($method)) {
            return null;
        }

        $facade = $this->facadeOnLine($frame, $method);

        return $facade !== null ? $facade.'::'.$method : ($callee['class'] ?? '').'->'.$method;
    }

    /**
     * The facade the source line calls `$method` on (Http in Http::pool(...)).
     *
     * @param  array<string, mixed>  $frame
     */
    private function facadeOnLine(array $frame, string $method): ?string
    {
        // eval()'d code has no file to read.
        if (! is_readable($frame['file'])) {
            return null;
        }

        $source = new SplFileObject($frame['file']);
        $source->seek($frame['line'] - 1);

        if (preg_match('/\\b([A-Z]\\w*)::'.preg_quote($method, '/').'\\s*\\(/', (string) $source->current(), $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function relative(string $file): string
    {
        return str_starts_with($file, $this->basePath) ? substr($file, strlen($this->basePath)) : $file;
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.exceptions')
            ->groupBy(['class' => 'by exception', 'source' => 'by source'])
            ->format(['message' => ['truncate', 80]])
            ->details('monocle::details.exception');
    }
}
