<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use Illuminate\Support\Str;

/**
 * Flattens the span tree into an ordered list with depth, ready to render as
 * an indented timeline.
 *
 * @internal
 */
final class TimelineBuilder
{
    /**
     * @param  list<string>  $kinds
     * @return list<array<string, mixed>>
     */
    public function build(Capture $capture, array $kinds = [], bool $verbose = false): array
    {
        $spans = $capture->spans;

        if ($kinds !== []) {
            $spans = array_filter($spans, fn (Span $span): bool => $this->isOfKind($span, $kinds));
        }

        // Spans whose parent was filtered out (or never recorded) become roots.
        $children = [];

        foreach ($spans as $span) {
            $parent = $span->parentId;

            if ($parent === null || ! isset($spans[$parent])) {
                $parent = '';
            }

            $children[$parent][] = $span;
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
     * "db" matches db.transaction as well as db itself.
     *
     * @param  list<string>  $kinds
     */
    private function isOfKind(Span $span, array $kinds): bool
    {
        if (in_array($span->kind, $kinds, true)) {
            return true;
        }

        return in_array(Str::before($span->kind, '.'), $kinds, true);
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
                $row['origin'] = $verbose ? $span->origin : $this->shortOrigin($span->origin);
            }

            $rows[] = $row;

            $this->walk($children, $span->id, $depth + 1, $rows, $verbose);
        }
    }

    /**
     * @param  array<string, mixed>  $origin
     */
    private function shortOrigin(array $origin): string
    {
        return ($origin['file'] ?? '?').':'.($origin['line'] ?? '?');
    }
}
