<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Storage\StoreManager;

/**
 * Lists, finds and traces captures using the store index wherever possible.
 *
 * @internal
 */
final class CaptureReader
{
    public function __construct(
        private readonly StoreManager $stores
    ) {
    }

    /**
     * @return array{total: int, returned: int, offset: int, next_offset: int|null, rows: list<array<string, mixed>>}
     */
    public function list(Filter $filter): array
    {
        $rows = array_values(array_filter(
            $this->stores->store()->index(),
            fn (array $row): bool => $filter->matches($row),
        ));
        $total = count($rows);
        $page = array_slice($rows, $filter->offset, $filter->limit);
        $next = $filter->offset + count($page);

        return [
            'total' => $total,
            'returned' => count($page),
            'offset' => $filter->offset,
            'next_offset' => $next < $total ? $next : null,
            'rows' => array_map($this->presentIndexRow(...), $page),
        ];
    }

    /**
     * Resolve a capture by id or the literal "latest".
     */
    public function find(string $id): ?Capture
    {
        if ($id === 'latest' || $id === '') {
            $index = $this->stores->store()->index();

            if ($index === []) {
                return null;
            }

            $id = $index[0]['id'];
        }

        return $this->stores->store()->find($id);
    }

    /**
     * The index row of a stored capture, without loading the capture itself.
     *
     * @return array<string, mixed>|null
     */
    public function indexRow(string $id): ?array
    {
        return array_find($this->stores->store()->index(), fn (array $row): bool => ($row['id'] ?? null) === $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function children(string $id): array
    {
        return array_values(array_filter(
            $this->stores->store()->index(),
            fn (array $row): bool => ($row['parent_id'] ?? null) === $id,
        ));
    }

    /**
     * Every capture sharing the trace of the given capture, oldest first, with a
     * depth computed from the parent chain.
     *
     * @return list<array<string, mixed>>
     */
    public function trace(string $traceId): array
    {
        $rows = array_values(array_filter(
            $this->stores->store()->index(),
            fn (array $row): bool => ($row['trace_id'] ?? null) === $traceId,
        ));

        usort($rows, fn (array $a, array $b): int => ($a['started_at'] ?? 0) <=> ($b['started_at'] ?? 0));

        $byId = array_column($rows, null, 'id');
        $root = null;

        foreach ($rows as $index => $row) {
            $depth = $this->depth($row, $byId);
            $rows[$index]['depth'] = $depth;

            if ($root === null && $depth === 0) {
                $root = $row;
            }
        }

        // Offsets count from the first root, or the oldest capture when the root is gone.
        $rootStart = (float) ($root['started_at'] ?? ($rows[0]['started_at'] ?? 0));

        return array_map(function (array $row) use ($rootStart): array {
            $row['offset_ms'] = round(((float) $row['started_at'] - $rootStart) * 1000, 3);

            return $this->presentIndexRow($row);
        }, $rows);
    }

    /**
     * Number of ancestors in the trace; capped so a parent cycle cannot loop forever.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $byId
     */
    private function depth(array $row, array $byId): int
    {
        $depth = 0;
        $parentId = $row['parent_id'] ?? null;

        while ($parentId !== null && isset($byId[$parentId]) && $depth < 50) {
            $depth++;
            $parentId = $byId[$parentId]['parent_id'] ?? null;
        }

        return $depth;
    }

    public function clear(): void
    {
        $this->stores->store()->clear();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function presentIndexRow(array $row): array
    {
        $row['started_at'] = isset($row['started_at'])
            ? date('c', (int) $row['started_at'])
            : null;

        return $row;
    }
}
