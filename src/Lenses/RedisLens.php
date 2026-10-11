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
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Events\CommandExecuted;
use Throwable;

/**
 * Every Redis command with its parameters, connection and duration. Turns on
 * the Redis manager's events itself.
 */
final class RedisLens implements Advises, Lens, Presentable, Summarizable
{
    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'redis';
    }

    public function description(): string
    {
        return 'Redis commands: command, parameters, connection and duration in ms. Includes the commands the cache and queue drivers issue when they use Redis.';
    }

    public function advice(): string
    {
        return 'Look here for Redis commands that do not go through the cache, such as locks, rate limiters and queues.';
    }

    public function boot(Application $app): void
    {
        if (! $app->bound('redis')) {
            return;
        }

        try {
            $app->make('redis')->enableEvents();
        } catch (Throwable) {
            return;
        }

        $this->eyepiece->listen(CommandExecuted::class, function (CommandExecuted $event): void {
            if (! $this->eyepiece->recording()) {
                return;
            }

            $this->eyepiece->push('redis', [
                'command' => $event->command,
                'parameters' => $event->parameters,
                'connection' => $event->connectionName,
                'duration' => round((float) $event->time, 3),
            ]);
        });
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('redis');

        if ($rows === []) {
            return new Summary();
        }

        $byCommand = [];
        $ms = 0.0;

        foreach ($rows as $row) {
            $command = strtoupper((string) $row['command']);
            $byCommand[$command] = ($byCommand[$command] ?? 0) + 1;
            $ms += (float) ($row['duration'] ?? 0);
        }

        arsort($byCommand);

        return new Summary(
            totals: [
                'redis_commands' => count($rows),
                'redis_ms' => round($ms, 3),
                'redis_by_command' => $byCommand,
            ],
        );
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.redis')
            ->groupBy(['command' => 'by command']);
    }
}
