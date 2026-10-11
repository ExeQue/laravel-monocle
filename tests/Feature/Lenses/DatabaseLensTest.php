<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\DatabaseLens;
use Illuminate\Support\Facades\DB;
use RuntimeException;

covers(DatabaseLens::class);

describe(DatabaseLens::class, function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('records queries with shape, type, tables and duplicates', function (): void {
        DB::table('things')->insert(['name' => 'a']);
        DB::table('things')->where('id', 1)->first();
        DB::table('things')->where('id', 1)->first();
        DB::table('things')->where('id', 2)->first();

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('queries');

        expect($rows)->toHaveCount(4)
            ->and($rows[0]['type'])->toBe('insert')
            ->and($rows[1]['type'])->toBe('select')
            ->and($rows[1]['tables'])->toBe(['things'])
            ->and($rows[1]['sql_with_bindings'])->toContain('= 1')
            ->and($rows[1]['bindings'])->toBe([1])
            ->and($rows[2]['duplicate_of'])->toBe(2)
            ->and($rows[3]['duplicate_of'])->toBeNull()
            ->and($rows[3]['shape'])->toBe($rows[1]['shape'])
            ->and($rows[1]['origin']['file'])->toContain('DatabaseLensTest.php')
            ->and($rows[1]['connection'])->toBe('testing')
            ->and($rows[1]['driver'])->toBe('sqlite');
    });

    it('leaves out queries that only touch ignored tables', function (): void {
        DB::statement('create table telescope_entries (id integer primary key, content text)');
        DB::table('telescope_entries')->insert(['content' => 'x']);
        DB::table('things')->join('telescope_entries', 'things.id', '=', 'telescope_entries.id')->get();
        DB::table('things')->get();

        $tables = array_values(array_filter(array_column(app(Eyepiece::class)->finalize()->rows('queries'), 'tables')));

        expect($tables)->toBe([['things', 'telescope_entries'], ['things']]);
    });

    it('wraps transactions in spans and tags queries with them', function (): void {
        DB::transaction(function (): void {
            DB::table('things')->insert(['name' => 'in-tx']);
        });

        try {
            DB::transaction(function (): void {
                DB::table('things')->insert(['name' => 'rollback']);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $capture = app(Eyepiece::class)->finalize();
        $transactions = $capture->spansOfKind('db.transaction');
        $rows = $capture->rows('queries');

        expect($transactions)->toHaveCount(2)
            ->and($transactions[0]->meta['outcome'])->toBe('committed')
            ->and($transactions[1]->meta['outcome'])->toBe('rolled_back')
            ->and(array_column($rows, 'sql'))->toBe([
                'BEGIN TRANSACTION', $rows[1]['sql'], 'COMMIT',
                'BEGIN TRANSACTION', $rows[4]['sql'], 'ROLLBACK',
            ])
            ->and($rows[0]['type'])->toBe('transaction')
            ->and($rows[1]['transaction_id'])->toBe($transactions[0]->id)
            ->and($rows[4]['transaction_id'])->toBe($transactions[1]->id)
            ->and($rows[5]['transaction_id'])->toBe($transactions[1]->id);
    });

    it('summarizes totals, slow queries and N+1 candidates', function (): void {
        $lens = app(DatabaseLens::class);
        $lens->configure(['slow_ms' => 0, 'n_plus_one_threshold' => 3]);

        foreach ([1, 2, 3] as $id) {
            DB::table('things')->where('id', $id)->first();
        }

        $capture = app(Eyepiece::class)->finalize();
        $summary = $lens->summarize($capture);

        expect($summary['totals']['queries'])->toBe(3)
            ->and($summary['totals']['queries_by_type'])->toBe(['select' => 3])
            ->and($summary['totals']['query_ms'])->toBeGreaterThan(0)
            ->and(implode(' ', $summary['warnings']))->toContain('Possible N+1: 3 x')
            ->and(\ExeQue\Monocle\Storage\Index::summarize($capture)['warnings'])->toBeGreaterThan(0);
    });
});
