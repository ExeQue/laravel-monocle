<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\ClearCommand;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Support\Facades\Route;

covers(ClearCommand::class);

describe(ClearCommand::class, function (): void {
    beforeEach(function (): void {
        Route::get('/things', fn () => 'ok');
        $this->get('/things')->assertOk();
        $this->get('/things')->assertOk();
    });

    $captures = fn (): int => count(app(StoreManager::class)->store()->index());

    it('clears the captures when the confirmation is accepted', function () use ($captures): void {
        $this->artisan('monocle:clear')
            ->expectsConfirmation('Delete all 2 recorded captures?', 'yes')
            ->expectsOutputToContain('Cleared 2 captures.')
            ->assertSuccessful();

        expect($captures())->toBe(0);
    });

    it('keeps the captures when the confirmation is declined', function () use ($captures): void {
        $this->artisan('monocle:clear')
            ->expectsConfirmation('Delete all 2 recorded captures?', 'no')
            ->expectsOutputToContain('Nothing was cleared.')
            ->assertSuccessful();

        expect($captures())->toBe(2);
    });

    it('skips the confirmation with --force', function () use ($captures): void {
        $this->artisan('monocle:clear', ['--force' => true])
            ->expectsOutputToContain('Cleared 2 captures.')
            ->assertSuccessful();

        expect($captures())->toBe(0);
    });

    it('never asks for confirmation with --json', function () use ($captures): void {
        $this->artisan('monocle:clear', ['--json' => true])
            ->expectsOutputToContain('"cleared": true')
            ->assertSuccessful();

        expect($captures())->toBe(0);
    });

    it('clears without asking when nothing is recorded', function () use ($captures): void {
        app(StoreManager::class)->store()->clear();

        $this->artisan('monocle:clear')
            ->expectsOutputToContain('Cleared 0 captures.')
            ->assertSuccessful();

        expect($captures())->toBe(0);
    });

    it('clears without asking when the command is not interactive', function () use ($captures): void {
        $this->artisan('monocle:clear', ['--no-interaction' => true])
            ->expectsOutputToContain('Cleared 2 captures.')
            ->assertSuccessful();

        expect($captures())->toBe(0);
    });
});
