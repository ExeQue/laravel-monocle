<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\ShowCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

covers(ShowCommand::class);

describe(ShowCommand::class, function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');
        Route::get('/things', fn () => DB::table('things')->where('id', 1)->first() ?? 'none');
        $this->get('/things')->assertOk();
    });

    it('renders the summary like the dashboard overview', function (): void {
        Artisan::call('monocle:show');
        $output = Artisan::output();

        expect($output)
            ->toContain('GET /things [request]')
            ->toContain('status 200')
            ->toContain('Phase')
            ->toContain('Category')
            ->toContain('Duration')
            ->toContain('queries')
            ->toContain('Sections (--section=...): summary, timeline');
    });

    it('renders the timeline as an indented tree', function (): void {
        Artisan::call('monocle:show', ['--section' => ['timeline']]);
        $output = Artisan::output();

        expect($output)
            ->toContain('Timeline')
            ->toContain('Start')
            ->toContain('Kind')
            ->toContain('Kind')
            ->toMatch('/phase\s+│ handle\s/')
            ->toMatch('/controller\s+│   controller\s/');
    });

    it('renders a row section as a table with only the useful columns', function (): void {
        Artisan::call('monocle:show', ['--section' => ['queries'], '--limit' => 1]);
        $output = Artisan::output();

        expect($output)
            ->toContain('Queries')
            ->toContain('Sql')
            ->toContain('select * from "things"')
            ->toContain('Duration')
            ->toContain(' ms ')
            ->not->toContain('Shape Hash');
    });

    it('still prints the JSON payload with --json', function (): void {
        Artisan::call('monocle:show', ['--json' => true, '--section' => ['summary', 'queries']]);
        $data = json_decode(Artisan::output(), true);

        expect($data['summary']['name'])->toBe('GET /things')
            ->and($data['queries']['rows'][0])->toHaveKey('sql');
    });

    it('fails with the error for unknown captures', function (): void {
        $this->artisan('monocle:show', ['id' => 'missing'])->assertFailed();
    });
});
