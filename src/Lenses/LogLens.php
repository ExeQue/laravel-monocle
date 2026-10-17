<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ErrorException;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use ExeQue\Monocle\Support\ThrowableChain;
use Illuminate\Log\Events\MessageLogged;
use Throwable;

/**
 * Log messages written during the capture, with level and context.
 */
final class LogLens implements Advises, Lens, Presentable, Summarizable
{
    private const array ERROR_LEVELS = ['emergency', 'alert', 'critical', 'error'];

    private const int MAX_ERRORS = 3;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'log';
    }

    public function description(): string
    {
        return 'Log captures written while handling the capture: level, message and context (keys matching mask.log masked). Exceptions in the context are reduced to class, message and file:line.';
    }

    public function advice(): string
    {
        return 'Look here for warnings and errors the application logged while handling the capture.';
    }

    public function boot(): void
    {
        $this->eyepiece->listen(MessageLogged::class, function (MessageLogged $event): void {
            if (! $this->eyepiece->recording()) {
                return;
            }

            // Monocle's own failure reports would otherwise record themselves.
            if (str_starts_with((string) $event->message, 'Monocle failed to record')) {
                return;
            }

            $row = [
                'level' => $event->level,
                'message' => $event->message,
                'context' => $this->context($event->context),
            ];

            // A PHP warning or deprecation comes from where it was raised, not from the code that happened to be running.
            $raised = $this->raisedAt($event);

            if ($raised !== null) {
                $row['origin'] = $this->eyepiece->originAt(...$raised);
            }

            $this->eyepiece->push('log', $row);
        });
    }

    /**
     * Laravel logs a PHP warning or deprecation with the ErrorException in the
     * context, or with " in FILE on line N" at the end of the message.
     *
     * @return array{file: string, line: int}|null
     */
    private function raisedAt(MessageLogged $event): ?array
    {
        $exception = $event->context['exception'] ?? null;

        if ($exception instanceof ErrorException) {
            return ['file' => $exception->getFile(), 'line' => $exception->getLine()];
        }

        if (preg_match('/ in (.+) on line (\d+)$/', (string) $event->message, $match) === 1) {
            return ['file' => $match[1], 'line' => (int) $match[2]];
        }

        return null;
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('log');

        if ($rows === []) {
            return Summary::make();
        }

        return Summary::make()
            ->total('log_captures', count($rows), 'entries')
            ->totals(['log_by_level' => collect($rows)->countBy('level')->all()])
            ->warnings($this->flagged($rows, ['warning']))
            ->errors($this->flagged($rows, self::ERROR_LEVELS));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $levels
     * @return list<string>
     */
    private function flagged(array $rows, array $levels): array
    {
        return collect($rows)
            ->whereInStrict('level', $levels)
            ->take(self::MAX_ERRORS)
            ->map(fn (array $row): string => sprintf('Logged %s: %s', $row['level'], $row['message']))
            ->values()
            ->all();
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function context(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $context[$key] = ThrowableChain::describe($value);
            }
        }

        // Keys matching mask.log (password, token ...) are masked; it is its own list, apart from request input.
        // A config published before mask.log existed has no such key, and keeps masking like input.
        $keys = $this->eyepiece->config('mask.log') === null ? 'mask.inputs' : 'mask.log';

        return $this->eyepiece->masker(self::class, $keys)->inputs(Normalizer::normalize($context));
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.log')
            ->groupBy(['message' => 'by message'])
            ->format(['level' => 'level', 'context' => 'fields']);
    }
}
