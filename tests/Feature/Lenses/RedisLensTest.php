<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\RedisLens;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;

covers(RedisLens::class);

describe(RedisLens::class, function (): void {
    $connection = function (string $name = 'default'): Connection {
        $connection = Mockery::mock(Connection::class)->shouldIgnoreMissing();
        $connection->shouldReceive('getName')->andReturn($name);

        return $connection;
    };

    it('records executed commands and summarizes them per command', function () use ($connection): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        event(new CommandExecuted('get', ['user:1'], 0.42, $connection(), 'default'));
        event(new CommandExecuted('SET', ['user:1', 'ada', 'EX', 60], 1.5, $connection(), 'default'));
        event(new CommandExecuted('get', ['user:2'], 0.3, $connection('cache'), 'cache'));

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('redis');

        expect($rows)->toHaveCount(3)
            ->and($rows[0])->toMatchArray(['command' => 'get', 'parameters' => ['user:1'], 'connection' => 'default', 'duration' => 0.42])
            ->and($rows[1]['parameters'])->toBe(['user:1', 'ada', 'EX', 60]);

        $summary = (new RedisLens())->summarize($capture);

        expect($summary['totals']['redis_commands'])->toBe(3)
            ->and($summary['totals']['redis_ms'])->toBe(2.22)
            ->and($summary['totals']['redis_by_command'])->toBe(['GET' => 2, 'SET' => 1]);
    });

    it('ignores commands outside a capture and summarizes nothing for none', function () use ($connection): void {
        event(new CommandExecuted('get', ['x'], 0.1, $connection(), 'default'));
        app(Eyepiece::class)->start(CaptureType::Request);

        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('redis'))->toBe([])
            ->and((new RedisLens())->summarize($capture))->toBe([]);
    });
});
