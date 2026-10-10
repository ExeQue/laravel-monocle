<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Queue;

use Closure;
use ExeQue\Monocle\Enums\EntryType;
use ExeQue\Monocle\Eyepiece;
use Throwable;

/**
 * Bus pipe that wraps commands handled inline (dispatchSync, dispatchNow and
 * the handler call inside a queue worker) in a job span.
 */
final class SyncDispatchPipe
{
    public function __construct(private readonly Eyepiece $eyepiece)
    {
    }

    public function handle(object $command, Closure $next): mixed
    {
        $entry = $this->eyepiece->active();

        if ($entry === null || ! $this->eyepiece->recording()) {
            return $next($command);
        }

        // Inside a worker the job entry already represents this command, and on
        // the sync queue the JobProcessing span does.
        if ($entry->type === EntryType::Job && $entry->name === $command::class) {
            return $next($command);
        }

        $current = $this->eyepiece->currentSpan();

        if ($current !== null && $current->kind === 'job' && $current->name === $command::class) {
            return $next($command);
        }

        $span = $this->eyepiece->span($command::class, 'job')->start([
            'sync' => true,
            'via' => 'dispatchSync',
        ]);

        try {
            return $next($command);
        } catch (Throwable $exception) {
            $span->meta(['status' => 'exception', 'exception' => ['class' => $exception::class, 'message' => $exception->getMessage()]]);

            throw $exception;
        } finally {
            if (($span->meta['status'] ?? null) === null) {
                $span->meta(['status' => 'processed']);
            }

            $span->stop();
        }
    }
}
