<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Process;

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\ThrowableChain;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns started and finished processes into rows in the processes section and
 * process spans on the timeline. Processes still open when the capture is
 * finalized are closed with an unknown outcome.
 *
 * @internal
 */
final class ProcessRecorder
{
    private int $tickets = 0;

    /** @var array<int, array{command: string, path: string|null, async: bool, started: float, parent: Span|null, origin: array<string, mixed>|null}> */
    private array $open = [];

    public function __construct(
        private readonly Eyepiece $eyepiece,
        private readonly string $basePath,
    ) {
    }

    /**
     * Note a process about to start; null when nothing is being recorded.
     */
    public function begin(string $command, ?string $path, bool $async): ?int
    {
        if (! $this->eyepiece->recording()) {
            return null;
        }

        $this->open[++$this->tickets] = [
            'command' => $command,
            'path' => $this->relative($path),
            'async' => $async,
            'started' => $this->eyepiece->now(),
            'parent' => $this->eyepiece->currentSpan(),
            'origin' => $this->eyepiece->origin(),
        ];

        return $this->tickets;
    }

    /**
     * Record the outcome of a process: its result, the exception it threw, or
     * neither when the outcome is unknown.
     */
    public function finish(?int $ticket, ?ProcessResult $result = null, ?Throwable $exception = null): void
    {
        if ($ticket === null) {
            return;
        }

        $process = $this->open[$ticket] ?? null;

        if ($process === null) {
            return;
        }

        unset($this->open[$ticket]);

        if (! $this->eyepiece->recording()) {
            return;
        }

        if ($exception instanceof ProcessTimedOutException) {
            $result = $exception->result;
        }

        $known = $result !== null || $exception !== null;
        $exitCode = $result?->exitCode();

        $span = $this->eyepiece->span(Str::limit($process['command'], 60), 'process')->parent($process['parent']);
        $span->origin = $process['origin'];
        $span->stop(['exit_code' => $exitCode, 'async' => $process['async']]);
        $span->start = $process['started'];

        $this->eyepiece->push('processes', [
            'command' => $process['command'],
            'path' => $process['path'],
            'exit_code' => $exitCode,
            'successful' => $known ? $exception === null && $result?->successful() === true : null,
            'timed_out' => $exception instanceof ProcessTimedOutException,
            'duration' => round($this->eyepiece->now() - $process['started'], 3),
            'time' => $process['started'],
            'output_bytes' => $this->bytes($result, 'output'),
            'error_output_bytes' => $this->bytes($result, 'errorOutput'),
            'async' => $process['async'],
            'exception' => $exception === null ? null : ThrowableChain::describe($exception),
            'origin' => $process['origin'],
        ]);
    }

    /**
     * Close the processes nobody waited for.
     */
    public function finalize(): void
    {
        foreach (array_keys($this->open) as $ticket) {
            $this->finish($ticket);
        }
    }

    public function reset(): void
    {
        $this->open = [];
    }

    private function relative(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        if ($path === $this->basePath) {
            return '.';
        }

        if (str_starts_with($path, $this->basePath.DIRECTORY_SEPARATOR)) {
            return substr($path, strlen($this->basePath) + 1);
        }

        return $path;
    }

    /**
     * Output length, or null when unknown or disabled with quietly().
     *
     * @param  'output'|'errorOutput'  $stream
     */
    private function bytes(?ProcessResult $result, string $stream): ?int
    {
        if ($result === null) {
            return null;
        }

        try {
            return strlen((string) $result->{$stream}());
        } catch (Throwable) {
            return null;
        }
    }
}
