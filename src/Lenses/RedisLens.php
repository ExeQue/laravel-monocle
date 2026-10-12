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
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Masker;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Events\CommandExecuted;
use Throwable;

/**
 * Every Redis command with its parameters, connection and duration. Turns on
 * the Redis manager's events itself.
 */
final class RedisLens implements Advises, Lens, Presentable, Summarizable
{
    /** @var array<string, int> commands writing one value => position of the value after the key */
    private const array VALUE_AT = ['SET' => 1, 'SETNX' => 1, 'GETSET' => 1, 'APPEND' => 1, 'SETEX' => 2, 'PSETEX' => 2];

    /** @var array<string, int> commands writing key value pairs => position of the first pair */
    private const array PAIRS_FROM = ['MSET' => 0, 'MSETNX' => 0, 'HSET' => 1, 'HSETNX' => 1, 'HMSET' => 1];

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
                'parameters' => $this->maskParameters($event->command, $event->parameters),
                'connection' => $event->connectionName,
                'duration' => round((float) $event->time, 3),
            ]);
        });
    }

    /**
     * Every argument of AUTH, the credentials after HELLO's AUTH, and values
     * written under a key or field matching the input mask pattern are masked.
     * Keyed arrays, as Laravel sends the pairs of MSET and HMSET, are masked
     * like input.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function maskParameters(string $command, array $parameters): array
    {
        $masker = $this->eyepiece->masker(self::class);
        $command = strtoupper($command);

        if ($command === 'AUTH') {
            return array_map(fn (mixed $value): mixed => $masker->mask($value, 'auth'), $parameters);
        }

        if ($command === 'HELLO') {
            return $this->maskHello($masker, $parameters);
        }

        $parameters = array_map(fn (mixed $value): mixed => is_array($value) ? $masker->inputs($value) : $value, $parameters);

        if (isset(self::VALUE_AT[$command])) {
            // The slice is empty when the value is missing, leaving the parameters as they are.
            foreach (array_slice($parameters, self::VALUE_AT[$command], 1, true) as $index => $value) {
                $parameters[$index] = $this->maskValue($masker, $parameters[0] ?? null, $value);
            }
        }

        if (isset(self::PAIRS_FROM[$command])) {
            for ($index = self::PAIRS_FROM[$command]; $index + 1 < count($parameters); $index += 2) {
                $parameters[$index + 1] = $this->maskValue($masker, $parameters[$index], $parameters[$index + 1]);
            }
        }

        return $parameters;
    }

    /**
     * HELLO [protover [AUTH username password] [SETNAME clientname]]
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function maskHello(Masker $masker, array $parameters): array
    {
        $words = array_map(fn (mixed $value): mixed => is_string($value) ? strtoupper($value) : $value, $parameters);
        $auth = array_search('AUTH', $words, true);

        if ($auth === false) {
            return $parameters;
        }

        foreach (array_slice($parameters, $auth + 1, 2, true) as $index => $value) {
            $parameters[$index] = $masker->mask($value, 'auth');
        }

        return $parameters;
    }

    private function maskValue(Masker $masker, mixed $key, mixed $value): mixed
    {
        if (! is_string($key)) {
            return $value;
        }

        return $masker->inputs([$key => $value])[$key];
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('redis');

        if ($rows === []) {
            return Summary::make();
        }

        $byCommand = collect($rows)->countBy(fn (array $row): string => strtoupper((string) $row['command']))->sortDesc()->all();
        $ms = collect($rows)->sum(fn (array $row): float => (float) ($row['duration'] ?? 0));

        return Summary::make()
            ->total('redis_ms', round($ms, 3), 'spent', Unit::Milliseconds)
            ->totals([
                'redis_by_command' => $byCommand,
            ])
            ->total('redis_commands', count($rows), 'commands');
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.redis')
            ->groupBy(['command' => 'by command']);
    }
}
