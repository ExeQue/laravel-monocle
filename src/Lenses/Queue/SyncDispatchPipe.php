<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Queue;

use Closure;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\ThrowableChain;
use Throwable;

/**
 * Bus pipe that wraps commands handled inline (dispatchSync, dispatchNow and
 * the handler call inside a queue worker) in a job span.
 *
 * @internal
 */
final class SyncDispatchPipe
{
    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function handle(object $command, Closure $next): mixed
    {
        $capture = $this->eyepiece->active();

        if ($capture === null) {
            return $next($command);
        }

        if (! $this->eyepiece->recording()) {
            return $next($command);
        }

        if ($this->alreadyTimed($capture, $command::class)) {
            return $next($command);
        }

        $span = $this->eyepiece->span($command::class, 'job')->start([
            'sync' => true,
            'via' => 'dispatchSync',
        ]);

        try {
            return $next($command);
        } catch (Throwable $exception) {
            $span->meta(['status' => 'exception', 'exception' => ThrowableChain::describe($exception)]);

            throw $exception;
        } finally {
            if (($span->meta['status'] ?? null) === null) {
                $span->meta(['status' => 'processed']);
            }

            $span->stop();
        }
    }

    /**
     * Inside a worker the job capture already represents this command, and on
     * the sync queue the JobProcessing span does.
     */
    private function alreadyTimed(Capture $capture, string $class): bool
    {
        if ($capture->type === CaptureType::Job && $capture->name === $class) {
            return true;
        }

        $current = $this->eyepiece->currentSpan();

        if ($current === null) {
            return false;
        }

        return $current->kind === 'job' && $current->name === $class;
    }
}
