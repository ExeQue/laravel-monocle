<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Dashboard;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Editor\EditorLinks;

/**
 * Builds the dashboard timeline: the span tree, with every timed row
 * (queries, cache, redis, http, filesystem) placed under the deepest span it
 * ran inside, and editor links on files and classes.
 */
final readonly class TimelinePresenter
{
    /** Section => node kind. */
    private const array TIMED_SECTIONS = ['queries' => 'db', 'cache' => 'cache', 'redis' => 'redis', 'http' => 'http', 'fs' => 'fs'];

    /** Row keys shown elsewhere in the node. */
    private const array HIDDEN_KEYS = ['time', 'duration', 'origin', 'sql_with_bindings', 'n'];

    public function __construct(private EditorLinks $links)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function present(Capture $capture): array
    {
        $nodes = [];
        $depth = [];

        foreach ($capture->spans as $span) {
            $nodes[$span->id] = $this->spanNode($span, $capture);
        }

        foreach ($capture->spans as $span) {
            $depth[$span->id] = $this->depth($span, $capture);
        }

        foreach (self::TIMED_SECTIONS as $section => $kind) {
            foreach ($capture->rows($section) as $index => $row) {
                if (! is_numeric($row['time'] ?? null)) {
                    continue;
                }

                $node = $this->rowNode("{$section}:{$index}", $kind, $section, $row);
                $node['parent'] = $this->container($node['s'], $node['e'], $capture, $depth);
                $nodes[$node['id']] = $node;
            }
        }

        return $this->tree($nodes);
    }

    /**
     * @return array<string, mixed>
     */
    private function spanNode(Span $span, Capture $capture): array
    {
        return [
            'id' => $span->id,
            'parent' => $span->parentId !== null && isset($capture->spans[$span->parentId]) ? $span->parentId : null,
            'kind' => $span->kind,
            'name' => $span->name,
            's' => round($span->start ?? 0.0, 3),
            'e' => round($span->end ?? $capture->duration(), 3),
            'd' => [
                'meta' => $this->meta($span->meta),
                'origin' => $this->origin($span->origin),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowNode(string $id, string $kind, string $section, array $row): array
    {
        $start = (float) $row['time'];

        return [
            'id' => $id,
            'parent' => null,
            'kind' => $kind,
            'name' => $this->label($section, $row),
            's' => round($start, 3),
            'e' => round($start + (float) ($row['duration'] ?? 0), 3),
            'd' => [
                'sql' => $row['sql_with_bindings'] ?? null,
                'meta' => $this->meta(array_diff_key($row, array_flip(self::HIDDEN_KEYS))),
                'origin' => $this->origin($row['origin'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function label(string $section, array $row): string
    {
        $text = match ($section) {
            'queries' => (string) ($row['sql'] ?? ''),
            'cache' => strtoupper((string) ($row['type'] ?? '')).' '.($row['key'] ?? ''),
            'redis' => strtoupper((string) ($row['command'] ?? '')).' '.implode(' ', array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '...', (array) ($row['parameters'] ?? []))),
            'http' => ($row['method'] ?? '').' '.($row['url'] ?? ''),
            'fs' => ($row['op'] ?? '').' '.($row['disk'] ?? '').':'.($row['path'] ?? ''),
            default => $section,
        };

        return mb_strimwidth(trim($text), 0, 300, '...');
    }

    /**
     * The deepest span that starts before and ends after the row.
     *
     * @param  array<string, int>  $depth
     */
    private function container(float $start, float $end, Capture $capture, array $depth): ?string
    {
        $best = null;

        foreach ($capture->spans as $span) {
            if ($span->start === null || $span->start > $start + 0.001 || ($span->end ?? INF) < $end - 0.05) {
                continue;
            }

            if ($best === null || $depth[$span->id] >= $depth[$best]) {
                $best = $span->id;
            }
        }

        return $best;
    }

    private function depth(Span $span, Capture $capture): int
    {
        $depth = 0;

        for ($parent = $span->parentId; $parent !== null && isset($capture->spans[$parent]) && $depth < 100; $parent = $capture->spans[$parent]->parentId) {
            $depth++;
        }

        return $depth;
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function tree(array $nodes): array
    {
        $children = [];

        foreach ($nodes as $id => $node) {
            $children[$node['parent'] ?? ''][] = $id;
        }

        $build = function (string $parent) use (&$build, $nodes, $children): array {
            $out = [];

            foreach ($children[$parent] ?? [] as $id) {
                $node = $nodes[$id];
                unset($node['parent']);
                $node['children'] = $build($id);
                $out[] = $node;
            }

            usort($out, fn (array $a, array $b): int => $a['s'] <=> $b['s']);

            return $out;
        };

        return $build('');
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function meta(array $meta): array
    {
        $links = array_filter([
            'class' => is_string($meta['class'] ?? null) ? $this->links->class($meta['class']) : null,
            'path' => is_string($meta['path'] ?? null) ? $this->links->file($meta['path']) : null,
        ]);

        return $links === [] ? $meta : $meta + ['_links' => $links];
    }

    /**
     * A section row with editor links on its origin and class/path values.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function row(array $row): array
    {
        if (is_array($row['origin'] ?? null)) {
            $row['origin'] = $this->origin($row['origin']);
        } elseif (is_string($row['origin'] ?? null)) {
            $row['origin'] = $this->originFromString($row['origin']);
        }

        return $this->meta($row);
    }

    /**
     * Origin given as "file:line", as the compact row and query views give it.
     *
     * @return array<string, mixed>|null
     */
    public function originFromString(string $origin): ?array
    {
        if (preg_match('/^(.*):(\d+|\?)$/', $origin, $match) !== 1) {
            return $this->origin(['file' => $origin]);
        }

        return $this->origin(['file' => $match[1], 'line' => is_numeric($match[2]) ? (int) $match[2] : null]);
    }

    /**
     * @param  array<string, mixed>|null  $origin
     * @return array<string, mixed>|null
     */
    public function origin(?array $origin): ?array
    {
        if ($origin === null || ! isset($origin['file'])) {
            return null;
        }

        $line = isset($origin['line']) ? (int) $origin['line'] : null;
        $frames = [];

        foreach ((array) ($origin['frames'] ?? []) as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'call' => $frame['call'] ?? null,
                'link' => isset($frame['file']) ? $this->links->file((string) $frame['file'], isset($frame['line']) ? (int) $frame['line'] : null) : null,
            ];
        }

        return [
            'file' => $origin['file'],
            'line' => $line,
            'call' => $origin['call'] ?? null,
            'link' => $this->links->file((string) $origin['file'], $line),
            'frames' => $frames,
        ];
    }
}
