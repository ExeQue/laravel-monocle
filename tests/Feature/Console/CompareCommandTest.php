<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\CompareCommand;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

    it('shows labels, queries that ran a different number of times and cache hits getting better', function (): void {
        Route::get('/once', function (): string {
            Cache::get('missing');
            DB::table('things')->where('id', 1)->first();

            return 'ok';
        });
        Route::get('/twice', function (): string {
            Cache::put('present', 1);
            Cache::get('present');
            DB::table('things')->where('id', 1)->first();
            DB::table('things')->where('id', 2)->first();

            return 'ok';
        });
        $this->get('/once', ['X-Monocle-Label' => 'baseline'])->assertOk();
        $this->get('/twice')->assertOk();

        Artisan::call('monocle:list', ['--json' => true, '--limit' => 2]);
        [$twice, $once] = array_column(json_decode(Artisan::output(), true)['rows'], 'id');

        Artisan::call('monocle:compare', ['a' => $once, 'b' => $twice]);
        $output = Artisan::output();

        expect($output)->toContain('[baseline]')
            ->toContain('Queries run a different number of times')
            ->toContain('Cache hit rate');
    });

    it('leaves out phases and categories when neither capture has spans', function (): void {
        foreach (['idle-a', 'idle-b'] as $id) {
            $capture = new Capture($id, CaptureType::Command, microtime(true));
            $capture->name = $id;
            $capture->status = '0';
            $capture->endedAt = $capture->startedAt + 0.01;
            app(StoreManager::class)->store()->put($capture);
        }

        Artisan::call('monocle:compare', ['a' => 'idle-a', 'b' => 'idle-b']);

        expect(Artisan::output())->toContain('A: idle-a')
            ->toContain('Duration')
            ->not->toContain('Phases')
            ->not->toContain('Categories');
    });
});
