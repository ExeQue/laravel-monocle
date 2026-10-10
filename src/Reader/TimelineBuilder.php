<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Entry;
use ExeQue\Monocle\Data\Span;

/**
 * Flattens the span tree into an ordered list with depth, ready to render as
 * an indented timeline.
 */
final class TimelineBuilder
{
    /**
     * @param  list<string>  $kinds
     * @return list<array<string, mixed>>
     */
    public function build(Entry $entry, array $kinds = [], bool $verbose = false): array
    {
        $spans = $entry->spans;

        if ($kinds !== []) {
            $spans = array_filter($spans, fn (Span $span): bool => in_array($span->kind, $kinds, true));
        }

        $children = [];

        foreach ($spans as $span) {
            $parent = $span->parentId !== null && isset($spans[$span->parentId]) ? $span->parentId : null;
            $children[$parent ?? ''][] = $span;
        }

        foreach ($children as &$group) {
            usort($group, fn (Span $a, Span $b): int => ($a->start ?? 0) <=> ($b->start ?? 0));
        }

        unset($group);

        $rows = [];
        $this->walk($children, '', 0, $rows, $verbose);

        return $rows;
    }

    /**
     * @param  array<string, list<Span>>  $children
     * @param  list<array<string, mixed>>  $rows
     */
    private function walk(array $children, string $parent, int $depth, array &$rows, bool $verbose): void
    {
        foreach ($children[$parent] ?? [] as $span) {
            $row = [
                'id' => $span->id,
                'depth' => $depth,
                'kind' => $span->kind,
                'name' => $span->name,
                'start' => $span->start,
                'duration' => $span->duration(),
                'meta' => $span->meta,
            ];

            if ($span->endedBy !== null && $span->endedBy !== 'stop') {
                $row['ended_by'] = $span->endedBy;
            }

            if ($span->origin !== null) {
                $row['origin'] = $verbose
                    ? $span->origin
                    : ($span->origin['file'] ?? '?') . ':' . ($span->origin['line'] ?? '?');
            }

            $rows[] = $row;

            $this->walk($children, $span->id, $depth + 1, $rows, $verbose);
        }
    }
}
