<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\RedisLens;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Mockery;
use RuntimeException;

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

        $summary = app(RedisLens::class)->summarize($capture);

        expect($summary->totals['redis_commands'])->toBe(3)
            ->and($summary->totals['redis_ms'])->toBe(2.22)
            ->and($summary->totals['redis_by_command'])->toBe(['GET' => 2, 'SET' => 1]);
    });

    it('masks credentials and values written under keys matching the input pattern', function () use ($connection): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        $commands = [
            ['auth', ['default', 'hunter22hunter22']],
            ['HELLO', [3, 'auth', 'default', 'hunter22hunter22', 'SETNAME', 'app']],
            ['hello', [3]],
            ['set', ['api_token', 'abcdefgh12345678', 'EX', 60]],
            ['set', ['user:1', 'ada']],
            ['setex', ['reset:password', 60, 'abcdefgh12345678']],
            ['set', [42, 'abcdefgh12345678']],
            ['set', ['password']],
            ['mset', ['secret', 'abcdefgh12345678', 'name', 'ada', 'dangling']],
            ['hset', ['user:1', 'password', 'abcdefgh12345678', 'name', 'ada']],
            ['hmset', ['user:1', ['password' => 'abcdefgh12345678', 'name' => 'ada']]],
            ['get', ['password']],
        ];

        foreach ($commands as [$command, $parameters]) {
            event(new CommandExecuted($command, $parameters, 0.1, $connection(), 'default'));
        }

        $parameters = array_column(app(Eyepiece::class)->finalize()->rows('redis'), 'parameters');

        expect($parameters)->toBe([
            ['d****', 'hunt****'],
            [3, 'auth', 'd****', 'hunt****', 'SETNAME', 'app'],
            [3],
            ['api_token', 'abcd****', 'EX', 60],
            ['user:1', 'ada'],
            ['reset:password', 60, 'abcd****'],
            [42, 'abcdefgh12345678'],
            ['password'],
            ['secret', 'abcd****', 'name', 'ada', 'dangling'],
            ['user:1', 'password', 'abcd****', 'name', 'ada'],
            ['user:1', ['password' => 'abcd****', 'name' => 'ada']],
            ['password'],
        ]);
    });

    it('ignores commands outside a capture and summarizes nothing for none', function () use ($connection): void {
        event(new CommandExecuted('get', ['x'], 0.1, $connection(), 'default'));
        app(Eyepiece::class)->start(CaptureType::Request);

        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('redis'))->toBe([])
            ->and(app(RedisLens::class)->summarize($capture))->toEqual(\ExeQue\Monocle\Data\Summary::make());
    });

    it('listens for nothing when redis is not bound', function (): void {
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('bound')->with('redis')->andReturnFalse();
        $app->shouldNotReceive('make');
        $listeners = count(app('events')->getListeners(CommandExecuted::class));

        new RedisLens(app(Eyepiece::class))->boot($app);

        expect(app('events')->getListeners(CommandExecuted::class))->toHaveCount($listeners);
    });

    it('listens for nothing when redis events cannot be enabled', function (): void {
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('bound')->with('redis')->andReturnTrue();
        $app->shouldReceive('make')->with('redis')->andThrow(new RuntimeException('no redis extension'));
        $listeners = count(app('events')->getListeners(CommandExecuted::class));

        new RedisLens(app(Eyepiece::class))->boot($app);

        expect(app('events')->getListeners(CommandExecuted::class))->toHaveCount($listeners);
    });

    it('presents redis commands as a table grouped by command', function (): void {
        $presentation = app(RedisLens::class)->presentation();

        expect($presentation->tabView)->toBe('monocle::tabs.redis')
            ->and($presentation->groupings)->toBe(['command' => 'by command']);
    });
});
