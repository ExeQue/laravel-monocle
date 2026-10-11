<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

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

        Artisan::command('monocle-test:ok', fn (): int => 0);

        Artisan::command('monocle-test:outer', function (): int {
            Artisan::call('monocle-test:ok');

            return 0;
        });

        Route::get('/call-ok', function () {
            Artisan::call('monocle-test:ok');

            return 'called';
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
        $summary = app(\ExeQue\Monocle\Reader\SummaryBuilder::class)->build($capture);

        expect($capture->section('command')['class'])->toBeNull()
            ->and($capture->sections)->not->toHaveKey('request')
            ->and($capture->health['open_spans_at_finalize'])->toBe(0)
            ->and($summary['warnings'])->toBe([])
            ->and($summary['totals']['memory_peak_mb'])->toBeGreaterThan(0)
            ->and(array_column($summary['breakdown']['phases'], 'name'))->toContain('command');
    });

    it('records the class behind a command', function (): void {
        Artisan::call('env');

        expect(latestCapture()->section('command')['class'])->toBe(\Illuminate\Foundation\Console\EnvironmentCommand::class);
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

    it('records nested commands as rows with masked options, exit code and duration', function (): void {
        Route::get('/call-secret', function () {
            Artisan::call('monocle-test:ping', ['name' => 'nested', '--secret' => 'hunter22hunter22']);

            return 'called';
        });

        $this->get('/call-secret')->assertOk();

        $request = latestCapture();
        $row = $request->rows('commands')[0];

        expect($request->rows('commands'))->toHaveCount(1)
            ->and($row['name'])->toBe('monocle-test:ping')
            ->and($row['class'])->toBeNull()
            ->and($row['arguments'])->toBe(['name' => 'nested'])
            ->and($row['options']['secret'])->toBe('hunt****')
            ->and($row['exit_code'])->toBe(3)
            ->and($row['failed'])->toBeTrue()
            ->and($row['duration'])->toBeFloat()
            ->and($row['time'])->toBeFloat()
            ->and($row['origin']['file'])->toContain('CommandLensTest.php');
    });

    it('reports a failed nested command as an error with totals', function (): void {
        $this->get('/call')->assertOk();

        $summary = app(\ExeQue\Monocle\Reader\SummaryBuilder::class)->build(latestCapture());

        expect($summary['errors'])->toContain('Command monocle-test:ping failed (exit code 3).')
            ->and($summary['totals']['commands'])->toBe(1)
            ->and($summary['totals']['commands_failed'])->toBe(1)
            ->and($summary['totals'])->toHaveKey('commands_ms')
            ->and($summary['problems']['command']['errors'])->toBe(1);
    });

    it('reports no error for a successful nested command and nothing without one', function (): void {
        $this->get('/call-ok')->assertOk();

        $lens = app(CommandLens::class);
        $ok = $lens->summarize(latestCapture());

        expect($ok->errors)->toBe([])
            ->and($ok->totals['commands_failed'])->toBe(0)
            ->and(latestCapture()->rows('commands')[0]['failed'])->toBeFalse()
            ->and($lens->summarize(new \ExeQue\Monocle\Data\Capture('c1', CaptureType::Request, microtime(true)))->totals)->toBe([]);
    });

    it('shows nested commands on a Commands tab, or on the command tab of a command capture', function (): void {
        $this->get('/call')->assertOk();

        $tabs = app(\ExeQue\Monocle\Dashboard\TabList::class)->for(latestCapture(), [], ['command' => ['warnings' => 0, 'errors' => 1]]);
        $tab = array_values(array_filter($tabs, fn (array $tab): bool => $tab['key'] === 'commands'))[0];

        Artisan::call('monocle-test:outer');

        $command = latestCapture();

        expect($tab)->toMatchArray(['label' => 'Commands', 'count' => 1, 'errors' => 1])
            ->and($command->section('command')['name'])->toBe('monocle-test:outer')
            ->and($command->section('command')['exit_code'])->toBe(0)
            ->and($command->rows('commands')[0]['name'])->toBe('monocle-test:ok')
            ->and(app(CommandLens::class)->presentation()->includes)->toBe(['commands']);
    });

    it('never records the Monocle MCP server process', function () use ($count): void {
        $input = new \Symfony\Component\Console\Input\ArrayInput(
            ['handle' => 'monocle'],
            new \Symfony\Component\Console\Input\InputDefinition([new \Symfony\Component\Console\Input\InputArgument('handle')]),
        );
        $output = new \Symfony\Component\Console\Output\NullOutput();

        event(new \Illuminate\Console\Events\CommandStarting('mcp:start', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('mcp:start', $input, $output, 0));

        expect($count('command'))->toBe(0)
            ->and(app(\ExeQue\Monocle\Eyepiece::class)->active())->toBeNull();
    });

    it('ignores excepted commands', function () use ($count): void {
        Artisan::call('monocle:list');

        expect($count('command'))->toBe(0);
    });
});
