<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\EntryType;
use ExeQue\Monocle\Lenses\CommandLens;
use ExeQue\Monocle\Reader\EntryReader;
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

    $count = fn (string $type): int => app(EntryReader::class)->list(Filter::fromArray(['type' => $type]))['total'];

    it('records a command run as an entry with arguments, masked options and exit code', function (): void {
        Artisan::call('monocle-test:ping', ['name' => 'ada', '--secret' => 'hunter22hunter22']);

        $entry = latestEntry();
        $command = $entry->section('command');

        expect($entry->type)->toBe(EntryType::Command)
            ->and($entry->name)->toBe('artisan monocle-test:ping')
            ->and($entry->status)->toBe('3')
            ->and($command['arguments']['name'])->toBe('ada')
            ->and($command['options']['secret'])->toBe('hunt****')
            ->and($command['exit_code'])->toBe(3)
            ->and(array_map(fn ($span): string => $span->name, $entry->spansOfKind('phase')))->toContain('command');
    });

    it('records nested commands as spans inside the active entry', function () use ($count): void {
        $this->get('/call')->assertOk();

        $request = latestEntry();
        $spans = $request->spansOfKind('command');

        expect($request->type)->toBe(EntryType::Request)
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->name)->toBe('monocle-test:ping')
            ->and($spans[0]->meta['exit_code'])->toBe(3)
            ->and($count('command'))->toBe(0);
    });

    it('ignores excepted commands', function () use ($count): void {
        Artisan::call('monocle:list');

        expect($count('command'))->toBe(0);
    });
});
