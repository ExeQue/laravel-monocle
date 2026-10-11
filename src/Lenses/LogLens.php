<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Throwable;

/**
 * Log messages written during the capture, with level and context.
 */
final class LogLens implements Advises, Lens, Summarizable
{
    private const array ERROR_LEVELS = ['emergency', 'alert', 'critical', 'error'];

    private Eyepiece $eyepiece;

    public function key(): string
    {
        return 'log';
    }

    public function description(): string
    {
        return 'Log captures written while handling the capture: level, message and context. Exceptions in the context are reduced to class, message and file:line.';
    }

    public function advice(): string
    {
        return 'Look here for warnings and errors the application logged while handling the capture.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        $eyepiece->listen(MessageLogged::class, function (MessageLogged $event): void {
            if (! $this->eyepiece->recording() || str_starts_with((string) $event->message, 'Monocle failed to record')) {
                return;
            }

            $this->eyepiece->push('log', [
                'level' => $event->level,
                'message' => $event->message,
                'context' => $this->context($event->context),
            ]);
        });
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('log');

        if ($rows === []) {
            return [];
        }

        $byLevel = [];
        $warnings = [];

        foreach ($rows as $row) {
            $byLevel[$row['level']] = ($byLevel[$row['level']] ?? 0) + 1;

            if (in_array($row['level'], self::ERROR_LEVELS, true) && count($warnings) < 3) {
                $warnings[] = sprintf('Logged %s: %s', $row['level'], $row['message']);
            }
        }

        return [
            'totals' => ['log_captures' => count($rows), 'log_by_level' => $byLevel],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function context(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $context[$key] = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile().':'.$value->getLine(),
                ];
            }
        }

        return Normalizer::normalize($context);
    }
}
