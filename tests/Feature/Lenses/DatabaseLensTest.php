<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\DatabaseLens;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
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

    it('names the relation a lazily loaded query came from as via', function (): void {
        DB::statement('create table writers (id integer primary key, name text)');
        DB::statement('create table books (id integer primary key, writer_id integer, title text)');
        $writer = \Tests\Fixtures\Models\Writer::query()->create(['name' => 'Ada']);

        $writer->books;

        $query = collect(app(Eyepiece::class)->finalize()->rows('queries'))->last();
        $method = new ReflectionMethod(\Tests\Fixtures\Models\Writer::class, 'books');

        expect($query['sql'])->toContain('"books"')
            ->and($query['origin']['via']['call'])->toBe(\Tests\Fixtures\Models\Writer::class.'->books')
            ->and($query['origin']['via']['line'])->toBe($method->getStartLine())
            ->and($query['origin']['via']['file'])->toEndWith('tests/Fixtures/Models/Writer.php');
    });

    it('summarizes totals, slow queries and N+1 candidates', function (): void {
        $lens = app(DatabaseLens::class);
        $lens->configure(['slow_ms' => 0, 'n_plus_one_threshold' => 3]);

        foreach ([1, 2, 3] as $id) {
            DB::table('things')->where('id', $id)->first();
        }

        // The same query again is a duplicate, not another N+1 query.
        DB::table('things')->where('name', 'a')->first();
        DB::table('things')->where('name', 'a')->first();
        DB::table('things')->where('name', 'a')->first();

        $capture = app(Eyepiece::class)->finalize();
        $summary = $lens->summarize($capture);

        expect($summary->totals['queries'])->toBe(6)
            ->and($summary->totals['queries_by_type'])->toBe(['select' => 6])
            ->and($summary->totals['query_ms'])->toBeGreaterThan(0)
            ->and(implode(' ', $summary->warnings))->toContain('Possible N+1: 3 x')
            ->and(implode(' ', $summary->warnings))->not->toContain('"name"')
            ->and(array_column($capture->rows('queries'), 'n_plus_one', 'n'))->toBe([1 => 3, 2 => 3, 3 => 3])
            ->and(array_column($capture->rows('queries'), 'repeated', 'n'))->toBe([4 => 2])
            ->and(app(\ExeQue\Monocle\Reader\SummaryBuilder::class)->build($capture, children: false)['problems']['queries']['warnings'] ?? 0)->toBeGreaterThan(0)
            ->and(\ExeQue\Monocle\Storage\Index::summarize($capture)['warnings'])->toBeGreaterThan(0);
    });

    it('counts queries by type and transactions', function (): void {
        DB::transaction(function (): void {
            DB::table('things')->insert(['name' => 'a']);
            DB::table('things')->insert(['name' => 'b']);
            DB::table('things')->where('name', 'a')->update(['name' => 'c']);
        });
        DB::table('things')->where('name', 'b')->delete();
        DB::table('things')->count();

        $summary = app(DatabaseLens::class)->summarize(app(Eyepiece::class)->finalize());

        expect($summary->totals)->toMatchArray(['selects' => 1, 'inserts' => 2, 'updates' => 1, 'deletes' => 1, 'transactions' => 1]);
    });

    it('closes transactions still open at finalize as left open', function (): void {
        DB::beginTransaction();
        DB::table('things')->insert(['name' => 'open']);

        $capture = app(Eyepiece::class)->finalize();
        DB::rollBack();

        expect($capture->spansOfKind('db.transaction')[0]->meta['outcome'])->toBe('left_open');
    });

    it('records no transaction statements while no capture is recording', function (): void {
        DB::beginTransaction();
        app(Eyepiece::class)->discard();
        DB::commit();

        DB::beginTransaction();
        DB::commit();

        expect(app(Eyepiece::class)->active()->rows('queries'))->toHaveCount(1)
            ->and(app(Eyepiece::class)->active()->rows('queries')[0]['sql'])->toBe('BEGIN TRANSACTION');
    });

    it('keeps the plain SQL and no driver when the connection cannot render bindings', function (): void {
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getName')->andReturn('broken');
        $connection->shouldReceive('getDriverName')->andThrow(new RuntimeException('no driver'));
        $connection->shouldReceive('prepareBindings')->andReturnArg(0);
        $connection->shouldReceive('getQueryGrammar')->andThrow(new RuntimeException('no grammar'));

        event(new \Illuminate\Database\Events\QueryExecuted('select * from things where id = ?', [1], 1.0, $connection));

        $row = app(Eyepiece::class)->finalize()->rows('queries')[0];

        expect($row['connection'])->toBe('broken')
            ->and($row['driver'])->toBeNull()
            ->and($row['sql_with_bindings'])->toBeNull();
    });

    it('types common table expressions as selects and records every table without ignore_tables', function (): void {
        $lens = collect(app(\ExeQue\Monocle\LensRegistry::class)->booted())->first(fn ($lens): bool => $lens instanceof DatabaseLens);
        $lens->configure(['ignore_tables' => []]);

        DB::statement('create table telescope_entries (id integer primary key, content text)');
        DB::select('with recent as (select * from things) select * from recent');
        DB::table('telescope_entries')->get();

        $rows = app(Eyepiece::class)->finalize()->rows('queries');
        $lens->configure(['ignore_tables' => ['telescope_*', 'pulse_*']]);

        expect(array_column($rows, 'type'))->toBe(['other', 'select', 'select'])
            ->and($rows[2]['tables'])->toBe(['telescope_entries']);
    });

    it('keeps the values out of every stored field when bindings are off', function (): void {
        $lens = collect(app(\ExeQue\Monocle\LensRegistry::class)->booted())->first(fn ($lens): bool => $lens instanceof DatabaseLens);
        $lens->configure(['bindings' => false]);

        DB::table('things')->where('name', 'secret-value')->first();
        DB::table('things')->where('name', 'secret-value')->first();
        DB::table('things')->where('name', 'other-value')->first();

        $rows = app(Eyepiece::class)->finalize()->rows('queries');
        $lens->configure(['bindings' => true]);

        expect(json_encode($rows))->not->toContain('secret-value')->not->toContain('other-value')
            ->and($rows[0]['bindings'])->toBe([])
            ->and($rows[0]['sql_with_bindings'])->toBe($rows[0]['sql'])
            ->and($rows[1]['duplicate_of'])->toBe(1)
            ->and($rows[2]['duplicate_of'])->toBeNull();
    });

    it('masks bindings whose column matches the input mask pattern', function (): void {
        DB::statement('create table users (id integer primary key, email text, password text, remember_token text)');

        DB::table('users')->insert([
            ['email' => 'a@example.com', 'password' => 'first-password', 'remember_token' => 'token-first'],
            ['email' => 'b@example.com', 'password' => 'second-password', 'remember_token' => 'token-second'],
        ]);
        DB::table('users')->where('users.password', 'hunter2hunter2')->first();
        DB::table('users')->where('password', '!=', 'unequal-password')->whereIn('remember_token', ['token-aaaa', 'token-bbbb'])->first();
        DB::table('users')->where('id', 1)->update(['password' => 'updated-password', 'email' => 'c@example.com']);
        DB::select("select '?' as mark from users where `password` like ? and email = ?", ['literal-password', 'd@example.com']);
        DB::select('select ? as marker from users', ['unknown-column']);
        DB::select('select * from users where trim(lower(password)) = ? and lower(email) = ?', ['wrapped-password', 'f@example.com']);
        DB::select("select * from users where coalesce(password, '') = ? and coalesce(email, '') = ?", ['coalesced-password', 'g@example.com']);
        DB::select('select * from users where password between ? and ? and id not between ? and ?', ['between-low-password', 'between-high-password', 1, 9]);
        DB::select('select * from users where password >= ? and password < ? and id > ?', ['greater-password', 'lesser-password', 2]);
        DB::select('select * from users where password = lower(?) and email = upper(?)', ['passed-password', 'h@example.com']);
        DB::select('select * from users where ifnull(email, password) = ?', ['concat-password']);
        DB::table('users')->insert(['email' => 'e@example.com', 'password' => 'set-password', 'remember_token' => null]);

        $rows = array_values(array_filter(
            app(Eyepiece::class)->finalize()->rows('queries'),
            fn (array $row): bool => $row['type'] !== 'other',
        ));

        expect(json_encode($rows))
            ->not->toContain('first-password')->not->toContain('second-password')
            ->not->toContain('hunter2hunter2')->not->toContain('unequal-password')
            ->not->toContain('token-aaaa')->not->toContain('token-bbbb')
            ->not->toContain('updated-password')->not->toContain('literal-password')
            ->not->toContain('wrapped-password')->not->toContain('coalesced-password')
            ->not->toContain('between-low-password')->not->toContain('between-high-password')
            ->not->toContain('greater-password')->not->toContain('lesser-password')
            ->not->toContain('passed-password')->not->toContain('concat-password')
            ->and($rows[0]['bindings'])->toBe(['a@example.com', 'fir****', 'to****', 'b@example.com', 'sec****', 'tok****'])
            ->and($rows[1]['bindings'])->toBe(['hun****'])
            ->and($rows[1]['sql_with_bindings'])->toContain("\"password\" = 'hun****'")
            ->and($rows[2]['bindings'])->toBe(['uneq****', 'to****', 'to****'])
            ->and($rows[3]['bindings'])->toBe(['upda****', 'c@example.com', 1])
            ->and($rows[4]['bindings'])->toBe(['lite****', 'd@example.com'])
            ->and($rows[5]['bindings'])->toBe(['unknown-column'])
            ->and($rows[6]['bindings'])->toBe(['wrap****', 'f@example.com'])
            ->and($rows[7]['bindings'])->toBe(['coal****', 'g@example.com'])
            ->and($rows[8]['bindings'])->toBe(['betw****', 'betw****', 1, 9])
            ->and($rows[9]['bindings'])->toBe(['grea****', 'les****', 2])
            ->and($rows[10]['bindings'])->toBe(['pas****', 'h@example.com'])
            ->and($rows[11]['bindings'])->toBe(['con****'])
            ->and($rows[12]['bindings'])->toBe(['e@example.com', 'set****', null]);
    });

    it('reads the column through a Postgres cast and gives up on parentheses opened before the window', function (): void {
        $connection = DB::connection();
        $far = str_repeat(' ', 200);

        event(new \Illuminate\Database\Events\QueryExecuted('select * from users where password::text = ? and lower(email)::text = ?', ['cast-password', 'i@example.com'], 1.0, $connection));
        event(new \Illuminate\Database\Events\QueryExecuted("select * from users where coalesce(password,{$far}'') = ?", ['far-password'], 1.0, $connection));

        $rows = app(Eyepiece::class)->finalize()->rows('queries');

        expect($rows[0]['bindings'])->toBe(['cas****', 'i@example.com'])
            ->and($rows[1]['bindings'])->toBe(['far-password']);
    });

    it('warns about slow queries the application ran, slowest first', function (): void {
        $capture = app(Eyepiece::class)->finalize();
        $capture->sections['queries'] = [
            ['n' => 1, 'type' => 'select', 'sql' => 'select 1', 'shape' => 'shape-1', 'duration' => 120.0, 'slow' => true],
            ['n' => 2, 'type' => 'select', 'sql' => 'select   2', 'shape' => 'shape-2', 'duration' => 300.0, 'slow' => true],
            ['n' => 3, 'type' => 'select', 'sql' => 'select 3', 'shape' => 'shape-3', 'duration' => 500.0, 'slow' => true, 'origin' => ['file' => 'vendor/acme/x.php', 'vendor' => true]],
        ];

        $summary = app(DatabaseLens::class)->summarize($capture);

        expect($summary->warnings)->toBe(['Slow query (300.0 ms): select 2', 'Slow query (120.0 ms): select 1'])
            ->and($summary->totals['slow_queries'])->toBe(2);
    });

    it('describes itself for the agent and the dashboard', function (): void {
        $lens = app(DatabaseLens::class);

        expect($lens->description())->toContain('n_plus_one')
            ->and($lens->advice())->toStartWith('Look here first')
            ->and($lens->presentation()->tabView)->toBe('monocle::tabs.queries')
            ->and($lens->presentation()->detailsView)->toBe('monocle::details.query');
    });
});
