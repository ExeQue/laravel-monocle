<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Lenses\CommandLens;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

covers(CommandLens::class);

describe(CommandLens::class, function (): void {
    beforeEach(function (): void {
        Artisan::command('monocle-test:ping {name} {--secret=}', function (): int {
            $this->line('pong');

            return 3;
        });

        Route::get('/call', function () {
            Artisan::call('monocle-test:ping', ['name' => 'nested']);

            return 'called';
        });
    });

    $count = fn (string $type): int => app(CaptureReader::class)->list(Filter::fromArray(['type' => $type]))['total'];

    it('records a command run as a capture with arguments, masked options and exit code', function (): void {
        Artisan::call('monocle-test:ping', ['name' => 'ada', '--secret' => 'hunter22hunter22']);

        $capture = latestCapture();
        $command = $capture->section('command');

        expect($capture->type)->toBe(CaptureType::Command)
            ->and($capture->name)->toBe('artisan monocle-test:ping')
            ->and($capture->status)->toBe('3')
            ->and($command['arguments']['name'])->toBe('ada')
            ->and($command['options']['secret'])->toBe('hunt****')
            ->and($command['exit_code'])->toBe(3)
            ->and(array_map(fn ($span): string => $span->name, $capture->spansOfKind('phase')))->toContain('command');
    });

    it('closes its own phase and keeps request data out of command captures', function (): void {
        Artisan::call('monocle-test:ping', ['name' => 'ada']);

        $capture = latestCapture();
        $summary = app(ExeQue\Monocle\Reader\SummaryBuilder::class)->build($capture);

        expect($capture->section('command')['class'])->toBeNull()
            ->and($capture->sections)->not->toHaveKey('request')
            ->and($capture->health['open_spans_at_finalize'])->toBe(0)
            ->and($summary['warnings'])->toBe([])
            ->and($summary['totals']['memory_peak_mb'])->toBeGreaterThan(0)
            ->and(array_column($summary['breakdown']['phases'], 'name'))->toContain('command');
    });

    it('records the class behind a command', function (): void {
        Artisan::call('env');

        expect(latestCapture()->section('command')['class'])->toBe(Illuminate\Foundation\Console\EnvironmentCommand::class);
    });

    it('records nested commands as spans inside the active capture', function () use ($count): void {
        $this->get('/call')->assertOk();

        $request = latestCapture();
        $spans = $request->spansOfKind('command');

        expect($request->type)->toBe(CaptureType::Request)
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->name)->toBe('monocle-test:ping')
            ->and($spans[0]->meta['exit_code'])->toBe(3)
            ->and($count('command'))->toBe(0);
    });

    it('never records the Monocle MCP server process', function () use ($count): void {
        $input = new Symfony\Component\Console\Input\ArrayInput(
            ['handle' => 'monocle'],
            new Symfony\Component\Console\Input\InputDefinition([new Symfony\Component\Console\Input\InputArgument('handle')]),
        );
        $output = new Symfony\Component\Console\Output\NullOutput();

        event(new Illuminate\Console\Events\CommandStarting('mcp:start', $input, $output));
        event(new Illuminate\Console\Events\CommandFinished('mcp:start', $input, $output, 0));

        expect($count('command'))->toBe(0)
            ->and(app(ExeQue\Monocle\Eyepiece::class)->active())->toBeNull();
    });

    it('ignores excepted commands', function () use ($count): void {
        Artisan::call('monocle:list');

        expect($count('command'))->toBe(0);
    });
});
