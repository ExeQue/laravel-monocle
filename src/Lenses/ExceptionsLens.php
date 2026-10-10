<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Entry;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Exceptions reported through the exception handler, bound to the entry they
 * happened in, with the user-land frames of their trace.
 */
final class ExceptionsLens implements Lens, Summarizable
{
    private Eyepiece $eyepiece;

    private string $basePath = '';

    public function key(): string
    {
        return 'exceptions';
    }

    public function description(): string
    {
        return 'Exceptions reported during the entry: class, message, code, file:line, the user-land frames of the stack trace and the previous exception. Unreported exceptions (404s and other ignored ones) do not appear; the response status does.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;
        $this->basePath = rtrim($app->basePath(), '/') . '/';

        $handler = $app->make(ExceptionHandler::class);

        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (Throwable $exception): void {
            if (! $this->eyepiece->recording()) {
                return;
            }

            $this->eyepiece->push('exceptions', $this->describe($exception), origin: false);
        });
    }

    public function summarize(Entry $entry): array
    {
        $rows = $entry->rows('exceptions');

        if ($rows === []) {
            return [];
        }

        return [
            'totals' => ['exceptions' => count($rows)],
            'warnings' => array_map(
                fn (array $row): string => sprintf('%s: %s (%s)', $row['class'], $row['message'], $row['file']),
                array_slice($rows, 0, 3),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Throwable $exception): array
    {
        $previous = $exception->getPrevious();

        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $this->relative($exception->getFile()) . ':' . $exception->getLine(),
            'frames' => $this->frames($exception),
            'previous' => $previous === null ? null : [
                'class' => $previous::class,
                'message' => $previous->getMessage(),
                'file' => $this->relative($previous->getFile()) . ':' . $previous->getLine(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function frames(Throwable $exception): array
    {
        $frames = [];

        foreach ($exception->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || str_contains($file, '/vendor/')) {
                continue;
            }

            $call = isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') . $frame['function'] : ($frame['function'] ?? '');
            $frames[] = $this->relative($file) . ':' . ($frame['line'] ?? '?') . ' ' . $call;

            if (count($frames) >= 10) {
                break;
            }
        }

        return $frames;
    }

    private function relative(string $file): string
    {
        return str_starts_with($file, $this->basePath) ? substr($file, strlen($this->basePath)) : $file;
    }
}
