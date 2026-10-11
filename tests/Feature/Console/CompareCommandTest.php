<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\CompareCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

covers(CompareCommand::class);

describe(CompareCommand::class, function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');
        Route::get('/before', fn () => 'none');
        Route::get('/after', fn () => DB::table('things')->where('id', 1)->first() ?? 'none');
        $this->get('/before')->assertOk();
        $this->get('/after')->assertOk();

        Artisan::call('monocle:list', ['--json' => true]);
        [$this->after, $this->before] = array_column(json_decode(Artisan::output(), true)['rows'], 'id');
    });

    it('renders both captures, the totals and the new queries for people', function (): void {
        Artisan::call('monocle:compare', ['a' => $this->before, 'b' => $this->after]);
        $output = Artisan::output();

        expect($output)
            ->toContain('A: GET /before')
            ->toContain('B: GET /after')
            ->toContain($this->before)
            ->toMatch('/Duration ms\s+│ [\d.]+ m?s/')
            ->toMatch('/Queries\s+│ 0\s+│ 1\s+│ \+1/')
            ->toContain('Queries only in B')
            ->toContain('select * from "things"')
            ->not->toContain('"totals"');
    });

    it('fails with the error for an unknown capture', function (): void {
        $this->artisan('monocle:compare', ['a' => 'missing'])
            ->expectsOutputToContain('Capture [missing] not found by id or label.')
            ->assertFailed();
    });

    it('still prints JSON with --json', function (): void {
        Artisan::call('monocle:compare', ['a' => $this->before, 'b' => $this->after, '--json' => true]);
        $data = json_decode(Artisan::output(), true);

        expect($data['a']['id'])->toBe($this->before)
            ->and($data['totals']['queries'])->toMatchArray(['a' => 0, 'b' => 1, 'delta' => 1]);
    });
});
