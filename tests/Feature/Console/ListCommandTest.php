<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\ListCommand;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use RuntimeException;

covers(ListCommand::class);

describe(ListCommand::class, function (): void {
    beforeEach(function (): void {
        Route::get('/first', fn () => 'one');
        Route::get('/broken', fn () => throw new RuntimeException('boom'));
        $this->get('/first')->assertOk();
        $this->get('/broken')->assertServerError();
    });

    it('lists only captures matching --search', function (): void {
        Artisan::call('monocle:list', ['--search' => 'broken', '--json' => true]);

        expect(array_column(json_decode(Artisan::output(), true)['rows'], 'name'))->toBe(['GET /broken']);
    });

    it('renders captures as a table for people', function (): void {
        Artisan::call('monocle:list', ['--json' => true]);
        $id = json_decode(Artisan::output(), true)['rows'][0]['id'];

        Artisan::call('monocle:list');
        $output = Artisan::output();

        expect($output)
            ->toContain('GET /first')
            ->toContain('500')
            ->toContain('✗ GET /broken')
            ->toMatch('/\d+ errors?/')
            ->toContain($id)
            ->not->toContain('next_offset');
    });

    it('notes the next page when there are more captures', function (): void {
        $this->artisan('monocle:list', ['--limit' => 1])
            ->expectsOutputToContain('Showing 1-1 of 2. Next page: --offset=1')
            ->assertSuccessful();
    });

    it('filters by host', function (): void {
        $this->artisan('monocle:list', ['--host' => 'other.test'])
            ->expectsOutputToContain('No captures found.')
            ->assertSuccessful();
    });

    it('says so when nothing was recorded', function (): void {
        $this->artisan('monocle:list', ['--uri' => 'nothing-matches'])
            ->expectsOutputToContain('No captures found.')
            ->assertSuccessful();
    });

    it('still prints JSON with --json', function (): void {
        Artisan::call('monocle:list', ['--json' => true]);

        expect(json_decode(Artisan::output(), true))->toMatchArray(['total' => 2, 'next_offset' => null]);
    });

    it('shows tags, the label and warnings next to the name', function (): void {
        $capture = new Capture('tagged', CaptureType::Request, microtime(true));
        $capture->name = 'GET /tagged';
        $capture->status = '200';
        $capture->label = 'baseline';
        $capture->endedAt = $capture->startedAt + 0.01;
        $capture->health = ['warnings' => 2, 'tags' => [['label' => 'beta', 'tone' => 'muted']]];
        app(StoreManager::class)->store()->put($capture);

        Artisan::call('monocle:list');

        expect(Artisan::output())->toContain('GET /tagged [beta] (baseline)')
            ->toContain('2 warnings');
    });
});
