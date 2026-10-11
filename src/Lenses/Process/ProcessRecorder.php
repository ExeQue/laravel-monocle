<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Process;

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\ProcessLens;
use ExeQue\Monocle\Support\ThrowableChain;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult as IlluminateProcessResult;
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
    /** A value: quoted, or up to the next space or quote. */
    private const string VALUE = '(?<value>\'[^\']*\'|"[^"]*"|[^\s\'"]+)';

    /** --name=value, or NAME=value as when setting a variable for the command. */
    private const string ASSIGNED = '/(?<=^|\s|[\'"])(?<name>--[\w.-]+|[A-Za-z_]\w*)(?<separator>=)'.self::VALUE.'/';

    /** --name value; a value cannot start with a dash, as that is the next option. */
    private const string SEPARATE = '/(?<=^|\s|[\'"])(?<name>--[\w.-]+)(?<separator>[\'"]?\s+)(?<value>\'(?!-)[^\']*\'|"(?!-)[^"]*"|[^\s\'"-][^\s\'"]*)/';

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
            'command' => $this->mask($command),
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

        $timedOut = $exception instanceof ProcessTimedOutException;

        // A timeout still carries the result of the process up to that point.
        if ($timedOut) {
            $result = $exception->result;
        }

        $exitCode = $result?->exitCode();

        $span = $this->eyepiece->span(Str::limit($process['command'], 60), 'process');
        $span->parent($process['parent']);
        $span->origin = $process['origin'];
        $span->stop(['exit_code' => $exitCode, 'async' => $process['async']]);
        // The span is only made once the process finished, so its start is set back to when it began.
        $span->start = $process['started'];

        $this->eyepiece->push('processes', [
            'command' => $process['command'],
            'path' => $process['path'],
            'exit_code' => $exitCode,
            'successful' => $this->successful($result, $exception),
            'timed_out' => $timedOut,
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

    /**
     * The command line with the values of options and variables whose name
     * matches the input mask pattern masked.
     */
    private function mask(string $command): string
    {
        $mask = fn (array $match): string => $match['name'].$match['separator'].$this->maskValue(ltrim($match['name'], '-'), $match['value']);

        $command = (string) preg_replace_callback(self::ASSIGNED, $mask, $command);

        return (string) preg_replace_callback(self::SEPARATE, $mask, $command);
    }

    /**
     * A quoted value keeps its quotes around the masked text.
     */
    private function maskValue(string $name, string $value): string
    {
        $masker = $this->eyepiece->masker(ProcessLens::class);

        if (! in_array($value[0], ['\'', '"'], true)) {
            return (string) $masker->inputs([$name => $value])[$name];
        }

        $inner = substr($value, 1, -1);

        return $value[0].(string) $masker->inputs([$name => $inner])[$name].$value[0];
    }

    /**
     * Null when neither a result nor an exception tells how the process ended.
     */
    private function successful(?ProcessResult $result, ?Throwable $exception): ?bool
    {
        if ($exception !== null) {
            return false;
        }

        if ($result === null) {
            return null;
        }

        return $result->successful() === true;
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
     * Whether the process ran with quietly(), so its output was never kept.
     * Only a real run knows; a faked result keeps what it was given.
     */
    private function outputDisabled(ProcessResult $result): bool
    {
        if (! $result instanceof IlluminateProcessResult) {
            return false;
        }

        // The Symfony process is a protected property of the result.
        return (fn (): bool => $this->process->isOutputDisabled())->call($result);
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

        // Laravel reports a quiet process's output as '', which would read as 0 bytes.
        if ($this->outputDisabled($result)) {
            return null;
        }

        try {
            return strlen((string) $result->{$stream}());
        } catch (Throwable) {
            return null;
        }
    }
}
