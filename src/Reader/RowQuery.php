<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Support\Origin;
use ExeQue\Monocle\Support\Search;
use Illuminate\Support\Arr;

/**
 * Pagination, filtering, sorting and projection shared by everything that
 * returns rows to an agent or a UI.
 *
 * @internal
 */
final class RowQuery
{
    public const int MAX_LIMIT = 500;

    /**
     * @param  list<array{field: string, op: string, value: mixed}>  $where
     * @param  list<string>  $fields
     */
    public function __construct(
        public readonly int $offset = 0,
        public readonly int $limit = 50,
        public readonly ?string $sort = null,
        public readonly string $dir = 'asc',
        public readonly array $where = [],
        public readonly array $fields = [],
        public readonly bool $verbose = false,
        public readonly ?string $search = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $limit = (int) ($input['limit'] ?? 50);
        $dir = strtolower((string) ($input['dir'] ?? 'asc'));

        return new self(
            offset: max(0, (int) ($input['offset'] ?? 0)),
            limit: max(1, min(self::MAX_LIMIT, $limit)),
            sort: self::optionalString($input['sort'] ?? null),
            dir: $dir === 'desc' ? 'desc' : 'asc',
            where: self::whereClauses($input['where'] ?? []),
            fields: array_values(array_map('strval', (array) ($input['fields'] ?? []))),
            verbose: filter_var($input['verbose'] ?? false, FILTER_VALIDATE_BOOL),
            search: self::optionalString($input['search'] ?? null),
        );
    }

    /**
     * Clauses without a field are dropped; the operator defaults to "=".
     *
     * @return list<array{field: string, op: string, value: mixed}>
     */
    private static function whereClauses(mixed $input): array
    {
        $where = [];

        foreach ((array) $input as $clause) {
            if (! is_array($clause)) {
                continue;
            }

            if (! isset($clause['field'])) {
                continue;
            }

            $where[] = [
                'field' => (string) $clause['field'],
                'op' => (string) ($clause['op'] ?? '='),
                'value' => $clause['value'] ?? null,
            ];
        }

        return $where;
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{total: int, filtered_from: int, returned: int, offset: int, next_offset: int|null, rows: list<array<string, mixed>>}
     */
    public function apply(array $rows): array
    {
        $filteredFrom = count($rows);

        $rows = collect($rows)->filter(fn (array $row): bool => $this->matches($row));

        if ($this->sort !== null) {
            $rows = $rows->sortBy(fn (array $row): mixed => Arr::get($row, $this->sort), descending: $this->dir === 'desc');
        }

        $total = $rows->count();
        $page = $rows->slice($this->offset, $this->limit)->map(fn (array $row): array => $this->project($row))->values()->all();
        $nextOffset = $this->offset + count($page);

        return [
            'total' => $total,
            'filtered_from' => $filteredFrom,
            'returned' => count($page),
            'offset' => $this->offset,
            'next_offset' => $nextOffset < $total ? $nextOffset : null,
            'rows' => $page,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matches(array $row): bool
    {
        if (! Search::matches($row, $this->search)) {
            return false;
        }

        foreach ($this->where as $clause) {
            $actual = Arr::get($row, $clause['field']);
            $expected = $clause['value'];

            $passes = match ($clause['op']) {
                '=', '==' => $actual == $expected,
                '!=' => $actual != $expected,
                '>' => $actual > $expected,
                '<' => $actual < $expected,
                '>=' => $actual >= $expected,
                '<=' => $actual <= $expected,
                'contains' => is_string($actual) && str_contains(strtolower($actual), strtolower((string) $expected)),
                'starts_with' => is_string($actual) && str_starts_with(strtolower($actual), strtolower((string) $expected)),
                'in' => in_array($actual, (array) $expected, false),
                'null' => $actual === null,
                'not_null' => $actual !== null,
                default => true,
            };

            if (! $passes) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function project(array $row): array
    {
        if ($this->fields !== []) {
            $projected = [];

            foreach ($this->fields as $field) {
                Arr::set($projected, $field, Arr::get($row, $field));
            }

            $row = $projected;
        }

        if ($this->shortensOrigin($row)) {
            $row['origin'] = Origin::describe($row['origin']);
        }

        return $row;
    }

    /**
     * Origins are cut down to "file:line" unless asked for in full, either
     * with verbose or by projecting one of their fields.
     *
     * @param  array<string, mixed>  $row
     *
     * @phpstan-assert-if-true array{origin: array<string, mixed>} $row
     */
    private function shortensOrigin(array $row): bool
    {
        if ($this->verbose) {
            return false;
        }

        if (! is_array($row['origin'] ?? null)) {
            return false;
        }

        return ! $this->requestsOriginField();
    }

    private function requestsOriginField(): bool
    {
        foreach ($this->fields as $field) {
            if (str_starts_with($field, 'origin.')) {
                return true;
            }
        }

        return false;
    }
}
