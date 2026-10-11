<?php

declare(strict_types=1);

namespace Tests\Feature\Reader;

use ExeQue\Monocle\Console\CompareCommand;
use ExeQue\Monocle\Mcp\MonocleServer;
use ExeQue\Monocle\Mcp\Tools\CompareCaptures;
use ExeQue\Monocle\Reader\CaptureComparer;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

covers(CaptureComparer::class, CompareCaptures::class, CompareCommand::class, Viewfinder::class);

describe(CaptureComparer::class, function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');

        Route::get('/slow', function () {
            foreach ([1, 2, 3] as $id) {
                DB::table('things')->where('id', $id)->first();
            }

            return 'slow';
        });
        Route::get('/fast', function () {
            DB::table('things')->whereIn('id', [1, 2, 3])->get();

            return 'fast';
        });

        $this->get('/slow', ['X-Monocle-Label' => 'baseline'])->assertOk();
        $this->get('/fast')->assertOk();
    });

    it('diffs totals, phases and query shapes between a label and the latest capture', function (): void {
        $result = app(Viewfinder::class)->compare(['a' => 'baseline']);

        expect($result['a']['label'])->toBe('baseline')
            ->and($result['b']['name'])->toBe('GET /fast')
            ->and($result['totals']['queries'])->toMatchArray(['a' => 3, 'b' => 1, 'delta' => -2])
            ->and($result['totals']['queries']['percent'])->toBe(-66.7)
            ->and($result['phases'])->toHaveKey('routing')
            ->and($result['queries']['only_in_a'])->toHaveCount(1)
            ->and($result['queries']['only_in_a'][0]['count'])->toBe(3)
            ->and($result['queries']['only_in_b'])->toHaveCount(1)
            ->and($result['queries']['changed'])->toBe([])
            ->and(implode(' ', $result['warnings']['only_in_a']))->toContain('N+1')
            ->and($result['warnings']['only_in_b'])->toBe([]);
    });

    it('reports unknown references', function (): void {
        expect(app(Viewfinder::class)->compare(['a' => 'nope'])['error'])->toContain('[nope]')
            ->and(app(Viewfinder::class)->compare(['a' => 'baseline', 'b' => 'missing'])['error'])->toContain('[missing]');
    });

    it('is exposed as an MCP tool and an artisan command', function (): void {
        MonocleServer::tool(CompareCaptures::class, ['a' => 'baseline'])->assertOk()->assertHasNoErrors();
        MonocleServer::tool(CompareCaptures::class, ['a' => 'nope'])->assertHasErrors();

        Artisan::call('monocle:compare', ['a' => 'baseline', '--compact' => true]);
        $output = json_decode(trim(Artisan::output()), true);

        expect($output['totals']['queries']['delta'])->toBe(-2);
    });
});
