<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\ThrowableChain;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Exceptions reported through the exception handler, bound to the capture they
 * happened in, with the user-land frames of their trace.
 */
final class ExceptionsLens implements Advises, Lens, Presentable, Summarizable
{
    private string $basePath = '';

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
        return 'Exceptions reported during the capture: class, message, code, file:line, the user-land frames of the stack trace and the previous exception. Unreported exceptions (404s and other ignored ones) do not appear; the response status does.';
    }

    public function advice(): string
    {
        return 'Look here first when something went wrong: the exception, where it was thrown and the frames in the application that led to it.';
    }

    public function boot(Application $app): void
    {
        $this->basePath = rtrim($app->basePath(), '/').'/';

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

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('exceptions');

        if ($rows === []) {
            return Summary::make();
        }

        return Summary::make()
            ->total(['exceptions' => count($rows)])
            ->warning(array_map(
                fn (array $row): string => sprintf('%s: %s (%s)', $row['class'], $row['message'], $row['file']),
                array_slice($rows, 0, 3),
            ));
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

            $call = isset($frame['class']) ? $frame['class'].($frame['type'] ?? '::').$frame['function'] : ($frame['function'] ?? '');
            $frames[] = $this->relative($file).':'.($frame['line'] ?? '?').' '.$call;

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

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.exceptions')
            ->groupBy(['class' => 'by exception'])
            ->details('monocle::details.exception');
    }
}
