<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Process;

use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Throwable;

/**
 * A pending process that records what run() and start() did.
 *
 * @internal
 */
final class RecordingPendingProcess extends PendingProcess
{
    public function __construct(
        Factory $factory,
        private readonly ProcessRecorder $recorder,
    ) {
        parent::__construct($factory);
    }

    public function run(array|string|null $command = null, ?callable $output = null)
    {
        $this->withTrace();
        $commandLine = $this->commandLine($command);
        $ticket = $this->recorder->begin($commandLine, $this->workingDirectory(), async: false);

        try {
            $result = parent::run($command, $output);
        } catch (Throwable $exception) {
            $this->recorder->finish($ticket, exception: $exception);

            throw $exception;
        }

        $this->recorder->finish($ticket, $result);

        return $result;
    }

    public function start(array|string|null $command = null, ?callable $output = null)
    {
        $this->withTrace();
        $commandLine = $this->commandLine($command);
        $ticket = $this->recorder->begin($commandLine, $this->workingDirectory(), async: true);

        try {
            $process = parent::start($command, $output);
        } catch (Throwable $exception) {
            $this->recorder->finish($ticket, exception: $exception);

            throw $exception;
        }

        // Nothing is being recorded, so the process needs no watching.
        if ($ticket === null) {
            return $process;
        }

        return new RecordingInvokedProcess($process, $this->recorder, $ticket);
    }

    /**
     * Hand the trace to the process; variables set by the caller win.
     */
    private function withTrace(): void
    {
        $this->environment = [...$this->recorder->traceEnvironment(), ...$this->environment];
    }

    /**
     * The command line as Laravel hands it to Symfony.
     *
     * @param  array<array-key, string>|string|null  $command
     */
    private function commandLine(array|string|null $command): string
    {
        $command ??= $this->command;

        try {
            return $this->toSymfonyProcess($command)->getCommandLine();
        } catch (Throwable) {
            return is_array($command) ? implode(' ', $command) : (string) $command;
        }
    }

    private function workingDirectory(): ?string
    {
        $path = $this->path ?? getcwd();

        return $path === false ? null : (string) $path;
    }
}
