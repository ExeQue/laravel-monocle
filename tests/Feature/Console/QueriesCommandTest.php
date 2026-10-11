<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\QueriesCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

covers(QueriesCommand::class);

describe(QueriesCommand::class, function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');
        Route::get('/things', function (): string {
            foreach ([1, 2, 3, 4] as $id) {
                DB::table('things')->where('id', $id)->first();
            }

            return 'ok';
        });
        $this->get('/things')->assertOk();
    });

    it('renders the groups as a table and flags likely N+1 queries', function (): void {
        expect(Artisan::call('monocle:queries'))->toBe(0)
            ->and(Artisan::output())
            ->toContain('grouped by shape, sorted by total_ms')
            ->toContain('select * from "things" where "id" = ? limit ?')
            ->toContain('N+1?')
            ->toContain('Ran 4 times, likely N+1')
            ->toContain('Example of the top group')
            ->toContain('select * from "things" where "id" = 1 limit 1');
    });

    it('shows the caller relative to the project', function (): void {
        Artisan::call('monocle:queries', ['--group-by' => 'caller']);

        expect(Artisan::output())->toContain('QueriesCommandTest.php:')
            ->not->toContain(base_path().'/');
    });

    it('does not flag N+1 when grouping by table', function (): void {
        Artisan::call('monocle:queries', ['--group-by' => 'table']);

        expect(Artisan::output())->toContain('things')->not->toContain('N+1');
    });

    it('notes the next page', function (): void {
        DB::table('things')->insert(['name' => 'one']);
        Route::get('/two', fn (): int => DB::table('things')->count() + DB::table('things')->max('id'));
        $this->get('/two')->assertOk();

        Artisan::call('monocle:queries', ['--limit' => 1]);

        expect(Artisan::output())->toContain('Showing 1-1 of 2. Next page: --offset=1');
    });

    it('still prints JSON with --json', function (): void {
        Artisan::call('monocle:queries', ['--json' => true]);

        expect(json_decode(Artisan::output(), true)['rows'][0]['count'])->toBe(4);
    });

    it('fails for unknown captures', function (): void {
        $this->artisan('monocle:queries', ['id' => 'missing'])->assertFailed();
    });
});
