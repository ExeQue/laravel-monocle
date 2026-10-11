<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Process;

use Illuminate\Contracts\Process\InvokedProcess;
use Throwable;

/**
 * A process started in the background. It is recorded once its result is
 * known: when wait() returns, or when running() first reports it finished.
 *
 * @internal
 */
final class RecordingInvokedProcess implements InvokedProcess
{
    public function __construct(
        private readonly InvokedProcess $inner,
        private readonly ProcessRecorder $recorder,
        private ?int $ticket,
    ) {
    }

    public function id()
    {
        return $this->inner->id();
    }

    public function command()
    {
        return $this->inner->command();
    }

    public function signal(int $signal)
    {
        $this->inner->signal($signal);

        return $this;
    }

    public function running()
    {
        $running = $this->inner->running();

        if ($running) {
            return true;
        }

        if ($this->ticket === null) {
            return false;
        }

        try {
            $this->recorder->finish($this->ticket, $this->inner->wait());
        } catch (Throwable $exception) {
            $this->recorder->finish($this->ticket, exception: $exception);
        }

        $this->ticket = null;

        return false;
    }

    public function output()
    {
        return $this->inner->output();
    }

    public function errorOutput()
    {
        return $this->inner->errorOutput();
    }

    public function latestOutput()
    {
        return $this->inner->latestOutput();
    }

    public function latestErrorOutput()
    {
        return $this->inner->latestErrorOutput();
    }

    public function wait(?callable $output = null)
    {
        try {
            $result = $this->inner->wait($output);
        } catch (Throwable $exception) {
            $this->recorder->finish($this->ticket, exception: $exception);
            $this->ticket = null;

            throw $exception;
        }

        $this->recorder->finish($this->ticket, $result);
        $this->ticket = null;

        return $result;
    }

    public function waitUntil(?callable $output = null)
    {
        return $this->inner->waitUntil($output);
    }

    /**
     * stop(), ensureNotTimedOut() and macros of the wrapped process.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }
}
