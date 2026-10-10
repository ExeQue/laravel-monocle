<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Storage\StoreManager;

/**
 * Lists, finds and traces captures using the store index wherever possible.
 */
final class CaptureReader
{
    public function __construct(private readonly StoreManager $stores)
    {
    }

    /**
     * @return array{total: int, returned: int, offset: int, next_offset: int|null, rows: list<array<string, mixed>>}
     */
    public function list(Filter $filter): array
    {
        $rows = array_values(array_filter($this->stores->store()->index(), fn (array $row): bool => $filter->matches($row)));
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

        $byId = [];

        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }

        $root = null;

        foreach ($rows as &$row) {
            $depth = 0;
            $cursor = $row;

            while (($cursor['parent_id'] ?? null) !== null && isset($byId[$cursor['parent_id']]) && $depth < 50) {
                $cursor = $byId[$cursor['parent_id']];
                $depth++;
            }

            $row['depth'] = $depth;
            $root ??= $depth === 0 ? $row : null;
        }

        unset($row);

        $rootStart = (float) ($root['started_at'] ?? ($rows[0]['started_at'] ?? 0));

        return array_map(function (array $row) use ($rootStart): array {
            $row['offset_ms'] = round(((float) $row['started_at'] - $rootStart) * 1000, 3);

            return $this->presentIndexRow($row);
        }, $rows);
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
