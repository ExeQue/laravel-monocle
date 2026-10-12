<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Origin;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records every executed query with its shape, duplicates and transaction,
 * and wraps transactions in spans.
 */
final class DatabaseLens implements Advises, Configurable, Finalizable, Lens, Presentable, Resettable, Summarizable
{
    private float $slowMs = 70.0;

    private int $nPlusOneThreshold = 3;

    /** @var list<string> Queries touching only these tables (wildcards allowed) are not recorded. */
    private array $ignoreTables = ['telescope_*', 'pulse_*'];

    private bool $bindings = true;

    private int $counter = 0;

    /** @var array<string, int> hash => first row number */
    private array $seen = [];

    /** @var array<string, array{tables: list<string>, ignored: bool, type: string, shape: string, shape_hash: string, columns: array<int, ?string>}> */
    private array $parsed = [];

    /** @var array<int, array{0: Connection, 1: string, 2: array<mixed>}> Queries whose sql_with_bindings is filled in at finalize, by row number. */
    private array $unrendered = [];

    /** @var array<string, string> */
    private array $drivers = [];

    /** @var array<string, list<Span>> connection => open transaction spans */
    private array $transactions = [];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'queries';
    }

    public function description(): string
    {
        return 'Every executed query, plus transaction statements (BEGIN TRANSACTION, COMMIT, ROLLBACK and savepoints, type transaction, no duration): sql, bindings, sql_with_bindings, connection, duration, type, tables, shape (normalized SQL for grouping), transaction_id, slow flag, duplicate_of (row number of the identical earlier query), n_plus_one (how often its shape ran, on selects that look like an N+1), repeated (on the first of identical queries: how many later ones repeated it). Transactions are db.transaction spans in the timeline.';
    }

    public function advice(): string
    {
        return 'Look here first when a page is slow or does too much work: N+1 queries, slow and duplicate queries, each with the line that ran it.';
    }

    public function configure(array $options): void
    {
        $this->slowMs = (float) ($options['slow_ms'] ?? $this->slowMs);
        $this->nPlusOneThreshold = (int) ($options['n_plus_one_threshold'] ?? $this->nPlusOneThreshold);
        $this->bindings = (bool) ($options['bindings'] ?? $this->bindings);
        $this->ignoreTables = array_values((array) ($options['ignore_tables'] ?? $this->ignoreTables));
        $this->parsed = [];
    }

    public function boot(): void
    {
        $this->eyepiece->listen(QueryExecuted::class, $this->onQuery(...));
        $this->eyepiece->listen(TransactionBeginning::class, $this->onTransactionBeginning(...));
        $this->eyepiece->listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            $this->endTransaction($event->connectionName, 'committed');
        });
        $this->eyepiece->listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            $this->endTransaction($event->connectionName, 'rolled_back');
        });
    }

    public function finalize(Capture $capture): void
    {
        foreach ($this->transactions as $spans) {
            foreach ($spans as $span) {
                $span->stop(['outcome' => 'left_open']);
            }
        }

        $this->renderRawSql($capture);
        $this->markNPlusOne($capture);
        $this->markRepeats($capture);
    }

    /**
     * Gives the first of identical queries a `repeated` count: how many later
     * queries ran it again.
     */
    private function markRepeats(Capture $capture): void
    {
        $rows = $capture->sections['queries'] ?? [];

        /** @var array<int, int> $repeats first row number => how many later rows repeated it */
        $repeats = collect($rows)->pluck('duplicate_of')->filter()->countBy()->all();

        foreach ($rows as $index => $row) {
            $number = $row['n'] ?? 0;

            if (isset($repeats[$number])) {
                $capture->sections['queries'][$index]['repeated'] = $repeats[$number];
            }
        }
    }

    /**
     * Gives each select whose shape ran at least n_plus_one_threshold times
     * an `n_plus_one` count, so the rows behind an N+1 warning can be found.
     */
    private function markNPlusOne(Capture $capture): void
    {
        $rows = $capture->sections['queries'] ?? [];

        // Repeats with the same bindings are duplicates, not an N+1; count each distinct query once.
        /** @var array<string, int> $counts shape => distinct selects with that shape */
        $counts = collect($rows)
            ->filter($this->isDistinctSelect(...))
            ->countBy(fn (array $row): string => (string) ($row['shape'] ?? ''))
            ->all();

        foreach ($rows as $index => $row) {
            if (($row['type'] ?? null) !== 'select') {
                continue;
            }

            $count = $counts[(string) ($row['shape'] ?? '')] ?? 0;

            if ($count >= $this->nPlusOneThreshold) {
                $capture->sections['queries'][$index]['n_plus_one'] = $count;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isDistinctSelect(array $row): bool
    {
        if (($row['type'] ?? null) !== 'select') {
            return false;
        }

        return ($row['duplicate_of'] ?? null) === null;
    }

    public function reset(): void
    {
        $this->counter = 0;
        $this->seen = [];
        $this->parsed = [];
        $this->unrendered = [];
        $this->transactions = [];
    }

    public function summarize(Capture $capture): Summary
    {
        // Transaction statements are listed with the queries but are not queries themselves.
        $rows = collect($capture->rows('queries'))->reject(fn (array $row): bool => ($row['type'] ?? null) === 'transaction');
        $total = $rows->sum(fn (array $row): float => (float) ($row['duration'] ?? 0));
        $byType = $rows->countBy(fn (array $row): mixed => $row['type'] ?? 'other')->all();

        // Slow or duplicate queries from vendor code are not something the app can fix: keep them out of the warnings.
        $fromApp = $rows->reject(fn (array $row): bool => Origin::fromLibrary(is_array($row['origin'] ?? null) ? $row['origin'] : null));
        $slow = $fromApp->filter(fn (array $row): bool => (bool) ($row['slow'] ?? false));
        $duplicates = $fromApp->filter(fn (array $row): bool => ($row['duplicate_of'] ?? null) !== null)->count();

        $slowest = $slow
            ->sortByDesc(fn (array $row): mixed => $row['duration'] ?? 0)
            ->take(3)
            ->map(fn (array $row): string => sprintf('Slow query (%.1f ms): %s', $row['duration'], $this->truncate($row['sql'] ?? '')));

        // Each shape run often enough as a distinct select, with the origin of its first run.
        $nPlusOne = $rows
            ->filter($this->isDistinctSelect(...))
            ->groupBy(fn (array $row): string => (string) ($row['shape'] ?? ''))
            ->filter(fn (Collection $selects): bool => $selects->count() >= $this->nPlusOneThreshold)
            ->map(function (Collection $selects, string $shape): string {
                $origin = $selects->first()['origin'] ?? null;
                $where = Origin::describe(is_array($origin) ? $origin : null) ?? 'unknown origin';

                return sprintf('Possible N+1: %d x %s (%s)', $selects->count(), $this->truncate($shape), $where);
            });

        return Summary::make()
            ->total('query_ms', round($total, 3), 'spent', Unit::Milliseconds)
            ->totals([
                'queries_by_type' => $byType,
            ])
            ->total('queries', $rows->count(), 'queries')
            ->total('selects', $byType['select'] ?? 0, 'selects')
            ->total('inserts', $byType['insert'] ?? 0, 'inserts')
            ->total('updates', $byType['update'] ?? 0, 'updates')
            ->total('deletes', $byType['delete'] ?? 0, 'deletes')
            ->total('transactions', count($capture->spansOfKind('db.transaction')), 'transactions')
            ->total('slow_queries', $slow->count(), 'slow')
            ->total('duplicate_queries', $duplicates, 'duplicates')
            ->warnings($slowest)
            ->when($duplicates > 0)
            ->warning("{$duplicates} duplicate queries (same SQL and bindings). Use monocle-queries group_by=shape to inspect.")
            ->warnings($nPlusOne);
    }

    private function onQuery(QueryExecuted $event): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $sql = $event->sql;
        // An N+1 runs the same SQL over and over: parse each statement once.
        $parsed = $this->parsed[$sql] ??= $this->parse($sql);

        if ($parsed['ignored']) {
            return;
        }

        $this->counter++;

        // Duplicates are told apart by the real values; only masked values are stored.
        $bindings = $this->bindings ? $this->maskBindings($event->bindings, $parsed['columns']) : [];
        $hash = hash('xxh128', $sql.'|'.json_encode($event->bindings));
        $open = $this->transactions[$event->connectionName] ?? [];
        $duration = round((float) $event->time, 3);
        $transactionId = $open === [] ? null : end($open)->id;

        $this->eyepiece->push('queries', [
            'n' => $this->counter,
            'sql' => $sql,
            'bindings' => $bindings,
            // Filled in at finalize, after the response is sent: substituting bindings is not free.
            // Without bindings the values stay out, so the placeholders are kept.
            'sql_with_bindings' => $this->bindings ? null : $sql,
            'connection' => $event->connectionName,
            'driver' => $this->driver($event),
            'duration' => $duration,
            'type' => $parsed['type'],
            'tables' => $parsed['tables'],
            'shape' => $parsed['shape'],
            'shape_hash' => $parsed['shape_hash'],
            'transaction_id' => $transactionId,
            'slow' => $duration >= $this->slowMs,
            'duplicate_of' => $this->seen[$hash] ?? null,
        ]);

        $this->seen[$hash] ??= $this->counter;

        if ($this->bindings) {
            $this->unrendered[$this->counter] = [$event->connection, $sql, $bindings];
        }
    }

    /**
     * Mask each binding whose column matches the input mask pattern.
     *
     * @param  array<array-key, mixed>  $bindings
     * @param  array<int, ?string>  $columns
     * @return array<array-key, mixed>
     */
    private function maskBindings(array $bindings, array $columns): array
    {
        $masker = $this->eyepiece->masker(self::class);

        foreach ($bindings as $index => $value) {
            $column = $columns[$index] ?? null;

            if ($column === null) {
                continue;
            }

            // An empty value has nothing to hide, and stays readable as null.
            if ($value === null) {
                continue;
            }

            $bindings[$index] = $masker->inputs([$column => $value])[$column];
        }

        return $bindings;
    }

    /**
     * The SQL of each query with its bindings substituted, as Laravel's
     * toRawSql() writes it.
     */
    private function renderRawSql(Capture $capture): void
    {
        foreach ($capture->sections['queries'] ?? [] as $index => $row) {
            [$connection, $sql, $bindings] = $this->unrendered[$row['n'] ?? 0] ?? [null, null, null];

            if ($connection === null) {
                continue;
            }

            try {
                $prepared = $connection->prepareBindings($bindings);
                $rendered = $connection->getQueryGrammar()->substituteBindingsIntoRawSql($sql, $prepared);

                $capture->sections['queries'][$index]['sql_with_bindings'] = $rendered;
            } catch (Throwable) {
                // A connection that can no longer quote leaves the plain SQL.
            }
        }

        $this->unrendered = [];
    }

    private function onTransactionBeginning(TransactionBeginning $event): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $connection = $event->connectionName;
        $depth = count($this->transactions[$connection] ?? []);
        $level = $depth + 1;

        // A nested transaction is a savepoint on the outer one.
        $name = $depth === 0 ? 'transaction' : "transaction (savepoint {$depth})";
        $sql = $depth === 0 ? 'BEGIN TRANSACTION' : "SAVEPOINT trans{$level}";

        $span = $this->eyepiece->span($name, 'db.transaction');
        $span->start(['connection' => $connection, 'level' => $level]);

        $this->transactions[$connection][] = $span;
        $this->transactionRow($connection, $sql, $span);
    }

    private function endTransaction(string $connection, string $outcome): void
    {
        if (empty($this->transactions[$connection])) {
            return;
        }

        $level = count($this->transactions[$connection]);
        $span = array_pop($this->transactions[$connection]);
        $span->stop(['outcome' => $outcome]);

        $this->transactionRow($connection, $this->endTransactionSql($outcome, $level), $span);
    }

    private function endTransactionSql(string $outcome, int $level): string
    {
        $outermost = $level === 1;

        if ($outcome === 'committed') {
            return $outermost ? 'COMMIT' : "RELEASE SAVEPOINT trans{$level}";
        }

        return $outermost ? 'ROLLBACK' : "ROLLBACK TO SAVEPOINT trans{$level}";
    }

    /**
     * Transaction statements go straight to PDO and fire no query event;
     * list them with the queries so the order of work reads as it ran.
     */
    private function transactionRow(string $connection, string $sql, Span $span): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->counter++;

        $this->eyepiece->push('queries', [
            'n' => $this->counter,
            'sql' => $sql,
            'bindings' => [],
            'sql_with_bindings' => $sql,
            'connection' => $connection,
            'driver' => null,
            'duration' => null,
            'type' => 'transaction',
            'tables' => [],
            'shape' => $sql,
            'shape_hash' => substr(hash('xxh128', $sql), 0, 8),
            'transaction_id' => $span->id,
            'slow' => false,
            'duplicate_of' => null,
        ], origin: false);
    }

    private function driver(QueryExecuted $event): ?string
    {
        try {
            return $this->drivers[$event->connectionName] ??= $event->connection->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }

    private function type(string $sql): string
    {
        $keyword = strtolower(strtok(ltrim($sql, " \t\n("), " \t\n(") ?: '');

        return match ($keyword) {
            'select', 'insert', 'update', 'delete' => $keyword,
            'with' => 'select',
            default => 'other',
        };
    }

    /**
     * @return array{tables: list<string>, ignored: bool, type: string, shape: string, shape_hash: string}
     */
    private function parse(string $sql): array
    {
        $tables = $this->tables($sql);
        $shape = $this->shape($sql);

        return [
            'tables' => $tables,
            'ignored' => $this->onlyIgnoredTables($tables),
            'type' => $this->type($sql),
            'shape' => $shape,
            'shape_hash' => substr(hash('xxh128', $shape), 0, 8),
            'columns' => $this->columns($sql),
        ];
    }

    /**
     * The column each `?` placeholder is compared with or written to, by
     * position: `column = ?`, `!=`, `<>`, `like`, `in (?, ?)`, `update ...
     * set column = ?` and the column list of an insert. Null where the
     * column cannot be told.
     *
     * @return list<?string>
     */
    private function columns(string $sql): array
    {
        // Question marks inside string literals are not placeholders.
        preg_match_all("/'(?:[^'\\\\]|\\\\.)*'|\?/", $sql, $matches, PREG_OFFSET_CAPTURE);

        [$insertColumns, $valuesStart, $valuesEnd] = $this->insertColumns($sql);
        $columns = [];
        $inserted = 0;
        $previousEnd = 0;
        $previous = null;

        foreach ($matches[0] as [$token, $offset]) {
            if ($token !== '?') {
                continue;
            }

            if ($offset >= $valuesStart && $offset < $valuesEnd) {
                // Multi-row inserts repeat the column list for every row.
                $column = $insertColumns[$inserted++ % count($insertColumns)];
            } elseif (trim(substr($sql, $previousEnd, $offset - $previousEnd)) === ',') {
                // The next value in the same list, as in `in (?, ?)`.
                $column = $previous;
            } else {
                $column = $this->comparedColumn($sql, $offset);
            }

            $columns[] = $column;
            $previous = $column;
            $previousEnd = $offset + 1;
        }

        return $columns;
    }

    /**
     * The column in front of the placeholder at the offset, as in
     * `"users"."email" = ?`, `name like ?` or `id in (?`.
     */
    private function comparedColumn(string $sql, int $offset): ?string
    {
        // 200 characters is plenty for a quoted schema.table.column and its operator.
        $start = max(0, $offset - 200);
        $before = substr($sql, $start, $offset - $start);

        $pattern = '/([\w."`\[\]]+)\s*(?:=|!=|<>|\s(?:not\s+)?like|\s(?:not\s+)?in\s*\()\s*$/i';

        if (preg_match($pattern, $before, $match) !== 1) {
            return null;
        }

        return $this->columnName($match[1]);
    }

    /**
     * The column list of an `insert into t (a, b) values (?, ?), ...` and
     * where its value rows start and end.
     *
     * @return array{0: list<?string>, 1: int, 2: int}
     */
    private function insertColumns(string $sql): array
    {
        if (preg_match('/^\s*insert\b.*?\binto\s+[^\s(]+\s*\(([^)]*)\)\s*values\s*/is', $sql, $match) !== 1) {
            return [[], 0, 0];
        }

        $start = strlen($match[0]);
        preg_match('/(?:\(\s*\?(?:\s*,\s*\?)*\s*\)\s*,?\s*)*/A', $sql, $rows, 0, $start);

        $columns = array_map($this->columnName(...), explode(',', $match[1]));

        return [$columns, $start, $start + strlen($rows[0])];
    }

    /**
     * The bare column name of an identifier like `"users"."password"`.
     */
    private function columnName(string $identifier): ?string
    {
        $parts = explode('.', $identifier);
        $name = trim((string) end($parts), " \t\n`\"[]");

        return $name === '' ? null : $name;
    }

    /**
     * @param  list<string>  $tables
     */
    private function onlyIgnoredTables(array $tables): bool
    {
        if ($tables === []) {
            return false;
        }

        if ($this->ignoreTables === []) {
            return false;
        }

        return array_filter($tables, fn (string $table): bool => ! Str::is($this->ignoreTables, $table)) === [];
    }

    /**
     * @return list<string>
     */
    private function tables(string $sql): array
    {
        preg_match_all('/\b(?:from|join|into|update)\s+[`"\[]?([\w.]+)[`"\]]?/i', $sql, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Normalize literals and IN-lists so repeated queries with different
     * bindings share a shape.
     */
    private function shape(string $sql): string
    {
        $shape = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql) ?? $sql;
        $shape = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $shape) ?? $shape;
        $shape = preg_replace('/\(\s*\?(?:\s*,\s*\?)*\s*\)/', '(?)', $shape) ?? $shape;
        $shape = preg_replace('/\s+/', ' ', $shape) ?? $shape;

        return trim($shape);
    }

    private function truncate(string $sql, int $length = 120): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return strlen($sql) > $length ? substr($sql, 0, $length).'...' : $sql;
    }

    public function presentation(): Presentation
    {
        return Presentation::table()->tab('monocle::tabs.queries')->details('monocle::details.query');
    }
}
