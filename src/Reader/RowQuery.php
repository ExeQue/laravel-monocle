<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use Illuminate\Support\Arr;

/**
 * Pagination, filtering, sorting and projection shared by everything that
 * returns rows to an agent or a UI.
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
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $where = [];

        foreach ((array) ($input['where'] ?? []) as $clause) {
            if (! is_array($clause) || ! isset($clause['field'])) {
                continue;
            }

            $where[] = [
                'field' => (string) $clause['field'],
                'op' => (string) ($clause['op'] ?? '='),
                'value' => $clause['value'] ?? null,
            ];
        }

        return new self(
            offset: max(0, (int) ($input['offset'] ?? 0)),
            limit: max(1, min(self::MAX_LIMIT, (int) ($input['limit'] ?? 50))),
            sort: isset($input['sort']) && $input['sort'] !== '' ? (string) $input['sort'] : null,
            dir: strtolower((string) ($input['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            where: $where,
            fields: array_values(array_map('strval', (array) ($input['fields'] ?? []))),
            verbose: filter_var($input['verbose'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{total: int, filtered_from: int, returned: int, offset: int, next_offset: int|null, rows: list<array<string, mixed>>}
     */
    public function apply(array $rows): array
    {
        $filteredFrom = count($rows);

        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->matches($row)));

        if ($this->sort !== null) {
            usort($rows, function (array $a, array $b): int {
                $result = Arr::get($a, $this->sort) <=> Arr::get($b, $this->sort);

                return $this->dir === 'desc' ? -$result : $result;
            });
        }

        $total = count($rows);
        $page = array_slice($rows, $this->offset, $this->limit);
        $page = array_map(fn (array $row): array => $this->project($row), $page);
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

        if (! $this->verbose && isset($row['origin']) && is_array($row['origin']) && ! $this->requestsOriginField()) {
            $row['origin'] = ($row['origin']['file'] ?? '?') . ':' . ($row['origin']['line'] ?? '?');
        }

        return $row;
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
