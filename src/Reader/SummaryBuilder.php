<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Entry;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\LensRegistry;

/**
 * The agent's first view of an entry: where the time went, totals and
 * warnings. Lenses contribute through the Summarizable contract.
 */
final class SummaryBuilder
{
    /** Sections whose rows carry a `duration` that counts towards a category. */
    private const array SECTION_CATEGORIES = [
        'db' => 'queries',
        'cache' => 'cache',
        'redis' => 'redis',
        'http' => 'http',
        'fs' => 'fs',
        'mail' => 'mail',
    ];

    private const array SPAN_CATEGORIES = [
        'views' => 'view',
        'ai' => 'ai.agent',
        'jobs_sync' => 'job',
    ];

    private const array PHASES = ['bootstrap', 'middleware', 'routing', 'controller', 'response', 'terminate'];

    public function __construct(
        private readonly LensRegistry $registry,
        private readonly EntryReader $entries,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Entry $entry): array
    {
        $totals = [
            'duration_ms' => $entry->duration(),
        ];
        $warnings = [];

        foreach ($this->registry->summarizers() as $summarizer) {
            $contribution = $summarizer->summarize($entry);
            $totals = [...$totals, ...($contribution['totals'] ?? [])];
            $warnings = [...$warnings, ...array_values($contribution['warnings'] ?? [])];
        }

        if (is_numeric($entry->status) && (int) $entry->status >= 500) {
            $warnings[] = "Response status {$entry->status}.";
        }

        $openSpans = (int) ($entry->health['open_spans_at_finalize'] ?? 0);

        if ($openSpans > 0) {
            $warnings[] = "{$openSpans} span(s) were never stopped and were closed at finalize.";
        }

        $counts = [];

        foreach ($entry->sections as $section => $rows) {
            if (array_is_list($rows)) {
                $counts[$section] = count($rows);
            }
        }

        return [
            'id' => $entry->id,
            'type' => $entry->type->value,
            'name' => $entry->name,
            'status' => $entry->status,
            'label' => $entry->label,
            'started_at' => date('c', (int) $entry->startedAt),
            'ended_by' => $entry->endedBy,
            'parent_id' => $entry->parentId,
            'trace_id' => $entry->traceId,
            'children' => array_column($this->entries->children($entry->id), 'id'),
            'breakdown' => $this->breakdown($entry),
            'totals' => $totals,
            'counts' => $counts,
            'warnings' => array_values(array_unique($warnings)),
            'meta' => $entry->meta,
            'health' => $entry->health + ['sections' => array_keys($entry->sections)],
        ];
    }

    /**
     * Time per category. Phases are exclusive wall-clock slices; the other
     * categories overlap with them (a query runs inside the controller) and are
     * listed to show what the slices were spent on.
     *
     * @return array{phases: list<array<string, mixed>>, categories: list<array<string, mixed>>}
     */
    public function breakdown(Entry $entry): array
    {
        $total = max($entry->duration(), 0.001);
        $phases = [];
        $accounted = 0.0;

        foreach (self::PHASES as $phase) {
            [$ms, $count] = $phase === 'middleware'
                ? $this->middlewareSelfTime($entry)
                : $this->spanTime($entry, $phase === 'controller' ? 'controller' : 'phase', $phase === 'controller' ? null : $phase);

            if ($count === 0) {
                continue;
            }

            $accounted += $ms;
            $phases[] = $this->line($phase, $ms, $count, $total);
        }

        if ($phases !== []) {
            $phases[] = $this->line('other', max(0.0, round($total - $accounted, 3)), 1, $total);
        }

        $categories = [];

        foreach (self::SECTION_CATEGORIES as $category => $section) {
            $rows = $entry->rows($section);

            if ($rows === []) {
                continue;
            }

            $ms = array_sum(array_map(fn (array $row): float => (float) ($row['duration'] ?? 0), $rows));
            $categories[] = $this->line($category, round($ms, 3), count($rows), $total);
        }

        foreach (self::SPAN_CATEGORIES as $category => $kind) {
            [$ms, $count] = $this->spanTime($entry, $kind, null, topLevelOnly: true);

            if ($count > 0) {
                $categories[] = $this->line($category, $ms, $count, $total);
            }
        }

        return ['phases' => $phases, 'categories' => $categories];
    }

    /**
     * @return array{0: float, 1: int}
     */
    private function spanTime(Entry $entry, string $kind, ?string $name, bool $topLevelOnly = false): array
    {
        $ms = 0.0;
        $count = 0;

        foreach ($entry->spans as $span) {
            if ($span->kind !== $kind) {
                continue;
            }

            if ($name !== null && $span->name !== $name) {
                continue;
            }

            if ($topLevelOnly && $span->parentId !== null && isset($entry->spans[$span->parentId]) && $entry->spans[$span->parentId]->kind === $kind) {
                continue;
            }

            $ms += $span->duration() ?? 0.0;
            $count++;
        }

        return [round($ms, 3), $count];
    }

    /**
     * @return array{0: float, 1: int}
     */
    private function middlewareSelfTime(Entry $entry): array
    {
        $ms = 0.0;
        $count = 0;

        foreach ($entry->spansOfKind('middleware') as $span) {
            $ms += (float) ($span->meta['before_ms'] ?? 0) + (float) ($span->meta['after_ms'] ?? 0);
            $count++;
        }

        return [round($ms, 3), $count];
    }

    /**
     * @return array{name: string, ms: float, percent: float, count: int}
     */
    private function line(string $name, float $ms, int $count, float $total): array
    {
        return [
            'name' => $name,
            'ms' => $ms,
            'percent' => round($ms / $total * 100, 1),
            'count' => $count,
        ];
    }

    /**
     * @return list<Span>
     */
    public function phases(Entry $entry): array
    {
        return $entry->spansOfKind('phase');
    }
}
