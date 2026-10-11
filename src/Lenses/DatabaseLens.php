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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
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
        return 'Every executed query, plus transaction statements (BEGIN TRANSACTION, COMMIT, ROLLBACK and savepoints, type transaction, no duration): sql, bindings, sql_with_bindings, connection, duration, type, tables, shape (normalized SQL for grouping), transaction_id, slow flag, duplicate_of (row number of the identical earlier query). Transactions are db.transaction spans in the timeline.';
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
    }

    public function boot(): void
    {
        $this->eyepiece->listen(QueryExecuted::class, $this->onQuery(...));
        $this->eyepiece->listen(TransactionBeginning::class, $this->onTransactionBeginning(...));
        $this->eyepiece->listen(TransactionCommitted::class, fn (TransactionCommitted $event) => $this->endTransaction($event->connectionName, 'committed'));
        $this->eyepiece->listen(TransactionRolledBack::class, fn (TransactionRolledBack $event) => $this->endTransaction($event->connectionName, 'rolled_back'));
    }

    public function finalize(Capture $capture): void
    {
        foreach ($this->transactions as $spans) {
            foreach ($spans as $span) {
                $span->stop(['outcome' => 'left_open']);
            }
        }
    }

    public function reset(): void
    {
        $this->counter = 0;
        $this->seen = [];
        $this->transactions = [];
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = array_values(array_filter($capture->rows('queries'), fn (array $row): bool => ($row['type'] ?? null) !== 'transaction'));
        $total = 0.0;
        $slow = [];
        $duplicates = 0;
        $byType = [];
        $shapes = [];

        foreach ($rows as $row) {
            $duration = (float) ($row['duration'] ?? 0);
            $total += $duration;
            $byType[$row['type'] ?? 'other'] = ($byType[$row['type'] ?? 'other'] ?? 0) + 1;

            $fromLibrary = Origin::fromLibrary(is_array($row['origin'] ?? null) ? $row['origin'] : null);

            if (($row['slow'] ?? false) && ! $fromLibrary) {
                $slow[] = $row;
            }

            if (($row['duplicate_of'] ?? null) !== null && ! $fromLibrary) {
                $duplicates++;
            }

            if (($row['type'] ?? null) === 'select') {
                $shape = (string) ($row['shape'] ?? '');
                $shapes[$shape] ??= ['count' => 0, 'origin' => $row['origin'] ?? null];
                $shapes[$shape]['count']++;
            }
        }

        $warnings = [];

        usort($slow, fn (array $a, array $b): int => ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0));

        foreach (array_slice($slow, 0, 3) as $row) {
            $warnings[] = sprintf('Slow query (%.1f ms): %s', $row['duration'], $this->truncate($row['sql'] ?? ''));
        }

        if ($duplicates > 0) {
            $warnings[] = "{$duplicates} duplicate queries (same SQL and bindings). Use monocle-queries group_by=shape to inspect.";
        }

        foreach ($shapes as $shape => $info) {
            if ($info['count'] >= $this->nPlusOneThreshold) {
                $origin = Origin::describe(is_array($info['origin']) ? $info['origin'] : null) ?? 'unknown origin';
                $warnings[] = sprintf('Possible N+1: %d x %s (%s)', $info['count'], $this->truncate($shape), $origin);
            }
        }

        return Summary::make()
            ->total('query_ms', round($total, 3), 'spent', Unit::Milliseconds)
            ->totals([
                'queries_by_type' => $byType,
            ])
            ->total('queries', count($rows), 'queries')
            ->total('slow_queries', count($slow), 'slow')
            ->total('duplicate_queries', $duplicates, 'duplicates')
            ->warnings($warnings);
    }

    private function onQuery(QueryExecuted $event): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $sql = $event->sql;
        $tables = $this->tables($sql);

        if ($tables !== [] && $this->ignoreTables !== [] && array_filter($tables, fn (string $table): bool => ! Str::is($this->ignoreTables, $table)) === []) {
            return;
        }

        $this->counter++;

        $bindings = $this->bindings ? $event->bindings : [];
        $hash = hash('xxh128', $sql.'|'.json_encode($event->bindings));
        $shape = $this->shape($sql);
        $open = $this->transactions[$event->connectionName] ?? [];
        $duration = round((float) $event->time, 3);

        $this->eyepiece->push('queries', [
            'n' => $this->counter,
            'sql' => $sql,
            'bindings' => $bindings,
            'sql_with_bindings' => $this->rawSql($event),
            'connection' => $event->connectionName,
            'driver' => $this->driver($event),
            'duration' => $duration,
            'type' => $this->type($sql),
            'tables' => $tables,
            'shape' => $shape,
            'shape_hash' => substr(hash('xxh128', $shape), 0, 8),
            'transaction_id' => $open === [] ? null : end($open)->id,
            'slow' => $duration >= $this->slowMs,
            'duplicate_of' => $this->seen[$hash] ?? null,
        ]);

        $this->seen[$hash] ??= $this->counter;
    }

    private function onTransactionBeginning(TransactionBeginning $event): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $depth = count($this->transactions[$event->connectionName] ?? []);
        $span = $this->eyepiece->span('transaction'.($depth > 0 ? ' (savepoint '.$depth.')' : ''), 'db.transaction');
        $span->start(['connection' => $event->connectionName, 'level' => $depth + 1]);

        $this->transactions[$event->connectionName][] = $span;
        $this->transactionRow($event->connectionName, $depth === 0 ? 'BEGIN TRANSACTION' : 'SAVEPOINT trans'.($depth + 1), $span);
    }

    private function endTransaction(string $connection, string $outcome): void
    {
        if (empty($this->transactions[$connection])) {
            return;
        }

        $level = count($this->transactions[$connection]);
        $span = array_pop($this->transactions[$connection]);
        $span->stop(['outcome' => $outcome]);

        $sql = match ($outcome) {
            'committed' => $level === 1 ? 'COMMIT' : 'RELEASE SAVEPOINT trans'.$level,
            default => $level === 1 ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT trans'.$level,
        };

        $this->transactionRow($connection, $sql, $span);
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

    private function rawSql(QueryExecuted $event): ?string
    {
        try {
            return $event->toRawSql();
        } catch (Throwable) {
            return null;
        }
    }

    private function driver(QueryExecuted $event): ?string
    {
        try {
            return $event->connection->getDriverName();
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
        return Presentation::table()->tab('monocle::tabs.queries');
    }
}
