<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Support\Origin;

/**
 * Groups the queries section by shape, caller, table or transaction so the
 * agent sees repeated work instead of hundreds of individual rows.
 *
 * @internal
 */
final class QueryAggregator
{
    /**
     * @return list<array<string, mixed>>
     */
    public function group(Capture $capture, string $by = 'shape', string $sort = 'total_ms'): array
    {
        $groups = [];

        foreach ($capture->rows('queries') as $row) {
            if (($row['type'] ?? null) === 'transaction') {
                continue;
            }

            foreach ($this->keysFor($row, $by) as $key) {
                $group = &$groups[$key];

                $group ??= [
                    'key' => $key,
                    'count' => 0,
                    'total_ms' => 0.0,
                    'max_ms' => 0.0,
                    'example' => $row['sql_with_bindings'] ?? $row['sql'] ?? null,
                    'origin' => Origin::describe(is_array($row['origin'] ?? null) ? $row['origin'] : null),
                    'connections' => [],
                    'rows' => [],
                ];

                $duration = (float) ($row['duration'] ?? 0);
                $group['count']++;
                $group['total_ms'] += $duration;
                $group['max_ms'] = max($group['max_ms'], $duration);
                $group['connections'][$row['connection'] ?? 'default'] = true;
                $group['rows'][] = $row['n'] ?? null;

                unset($group);
            }
        }

        $groups = array_values(array_map(function (array $group): array {
            $group['total_ms'] = round($group['total_ms'], 3);
            $group['avg_ms'] = round($group['total_ms'] / max(1, $group['count']), 3);
            $group['connections'] = array_keys($group['connections']);

            return $group;
        }, $groups));

        $field = in_array($sort, ['total_ms', 'count', 'max_ms', 'avg_ms'], true) ? $sort : 'total_ms';

        usort($groups, fn (array $a, array $b): int => $b[$field] <=> $a[$field]);

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function keysFor(array $row, string $by): array
    {
        return match ($by) {
            'caller' => [is_array($row['origin'] ?? null)
                ? ($row['origin']['file'] ?? '?').':'.($row['origin']['line'] ?? '?')
                : 'unknown'],
            'table' => ($row['tables'] ?? []) === [] ? ['(none)'] : array_values($row['tables']),
            'transaction' => [(string) ($row['transaction_id'] ?? '(none)')],
            default => [(string) ($row['shape'] ?? $row['sql'] ?? '?')],
        };
    }
}
