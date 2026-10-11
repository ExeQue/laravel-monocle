<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\RowsCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

covers(RowsCommand::class);

describe(RowsCommand::class, function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');
        Route::get('/things', function (): string {
            DB::table('things')->where('id', 1)->first();
            DB::table('things')->where('id', 2)->first();
            DB::table('things')->count();

            return 'ok';
        });
        $this->get('/things')->assertOk();
    });

    it('renders rows as a table with a filter line and a paging note', function (): void {
        Artisan::call('monocle:rows', ['section' => 'queries', '--where' => ['type:=:select'], '--limit' => 2]);
        $output = Artisan::output();

        expect($output)->toContain('Queries of ')
            ->toContain('Filter: type = select (3 of 3 rows match)')
            ->toContain('Sql')
            ->toContain('select * from "things" where "id" = ?')
            ->toContain(' ms')
            ->toContain('Showing 1-2 of 3. Next page: --offset=2')
            ->not->toContain('Origin');
    });

    it('keeps only rows containing --search', function (): void {
        Artisan::call('monocle:rows', ['section' => 'queries', '--search' => 'count']);
        $output = Artisan::output();

        expect($output)->toContain('containing "count"')
            ->toContain('count(*)')
            ->not->toContain('where "id" = ?');
    });

    it('renders a single row as a key/value table', function (): void {
        Artisan::call('monocle:rows', ['section' => 'queries', '--limit' => 1]);

        expect(Artisan::output())->toContain('origin')->toContain('sql_with_bindings');
    });

    it('only shows the given fields', function (): void {
        Artisan::call('monocle:rows', ['section' => 'queries', '--field' => ['sql']]);

        expect(Artisan::output())->toContain('Sql')->not->toContain('Duration');
    });

    it('still prints JSON when asked', function (): void {
        Artisan::call('monocle:rows', ['section' => 'queries', '--json' => true]);

        expect(json_decode(Artisan::output(), true))->toMatchArray(['section' => 'queries', 'total' => 3]);
    });

    it('fails for unknown captures', function (): void {
        $this->artisan('monocle:rows', ['section' => 'queries', 'id' => 'missing'])->assertFailed();
    });

    it('renders a data section as a key/value table', function (): void {
        Artisan::call('monocle:rows', ['section' => 'request']);

        expect(Artisan::output())->toContain('Request of ')
            ->toContain('method')
            ->toContain('GET');
    });

    it('names the sort in the filter line', function (): void {
        Artisan::call('monocle:rows', ['section' => 'queries', '--sort' => 'duration', '--dir' => 'desc']);

        expect(Artisan::output())->toContain('sorted by duration desc');
    });
});
