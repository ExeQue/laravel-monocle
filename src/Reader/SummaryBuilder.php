<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\LensRegistry;

/**
 * The agent's first view of a capture: where the time went, totals and
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

    private const array PHASES = ['bootstrap', 'middleware (request)', 'routing', 'controller', 'middleware (response)', 'response', 'terminate'];

    public function __construct(
        private readonly LensRegistry $registry,
        private readonly CaptureReader $captures,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Capture $capture): array
    {
        $totals = [
            'duration_ms' => $capture->duration(),
            'memory_peak_mb' => isset($capture->health['memory_peak_bytes']) ? round($capture->health['memory_peak_bytes'] / 1048576, 1) : null,
        ];
        $warnings = [];

        foreach ($this->registry->summarizers() as $summarizer) {
            $contribution = $summarizer->summarize($capture);
            $totals = [...$totals, ...($contribution['totals'] ?? [])];
            $warnings = [...$warnings, ...array_values($contribution['warnings'] ?? [])];
        }

        if (is_numeric($capture->status) && (int) $capture->status >= 500) {
            $warnings[] = "Response status {$capture->status}.";
        }

        $openSpans = (int) ($capture->health['open_spans_at_finalize'] ?? 0);

        if ($openSpans > 0) {
            $warnings[] = "{$openSpans} span(s) were never stopped and were closed at finalize.";
        }

        $counts = [];

        foreach ($capture->sections as $section => $rows) {
            if (array_is_list($rows)) {
                $counts[$section] = count($rows);
            }
        }

        return [
            'id' => $capture->id,
            'type' => $capture->type->value,
            'name' => $capture->name,
            'status' => $capture->status,
            'label' => $capture->label,
            'started_at' => date('c', (int) $capture->startedAt),
            'ended_by' => $capture->endedBy,
            'parent_id' => $capture->parentId,
            'trace_id' => $capture->traceId,
            'children' => array_column($this->captures->children($capture->id), 'id'),
            'breakdown' => $this->breakdown($capture),
            'totals' => $totals,
            'counts' => $counts,
            'warnings' => array_values(array_unique($warnings)),
            'meta' => $capture->meta,
            'health' => $capture->health + ['sections' => array_keys($capture->sections)],
        ];
    }

    /**
     * Time per category. Phases are exclusive wall-clock slices; the other
     * categories overlap with them (a query runs inside the controller) and are
     * listed to show what the slices were spent on.
     *
     * @return array{phases: list<array<string, mixed>>, categories: list<array<string, mixed>>}
     */
    public function breakdown(Capture $capture): array
    {
        $total = max($capture->duration(), 0.001);
        $phases = [];
        $accounted = 0.0;

        foreach (self::PHASES as $phase) {
            [$ms, $count] = match ($phase) {
                'middleware (request)' => $this->spanTime($capture, 'middleware.request', null),
                'middleware (response)' => $this->spanTime($capture, 'middleware.response', null),
                'controller' => $this->spanTime($capture, 'controller', null),
                default => $this->spanTime($capture, 'phase', $phase),
            };

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
            $rows = $capture->rows($section);

            if ($rows === []) {
                continue;
            }

            $ms = array_sum(array_map(fn (array $row): float => (float) ($row['duration'] ?? 0), $rows));
            $categories[] = $this->line($category, round($ms, 3), count($rows), $total);
        }

        foreach (self::SPAN_CATEGORIES as $category => $kind) {
            [$ms, $count] = $this->spanTime($capture, $kind, null, topLevelOnly: true);

            if ($count > 0) {
                $categories[] = $this->line($category, $ms, $count, $total);
            }
        }

        return ['phases' => $phases, 'categories' => $categories];
    }

    /**
     * @return array{0: float, 1: int}
     */
    private function spanTime(Capture $capture, string $kind, ?string $name, bool $topLevelOnly = false): array
    {
        $ms = 0.0;
        $count = 0;

        foreach ($capture->spans as $span) {
            if ($span->kind !== $kind) {
                continue;
            }

            if ($name !== null && $span->name !== $name) {
                continue;
            }

            if ($topLevelOnly && $span->parentId !== null && isset($capture->spans[$span->parentId]) && $capture->spans[$span->parentId]->kind === $kind) {
                continue;
            }

            $ms += $span->duration() ?? 0.0;
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
    public function phases(Capture $capture): array
    {
        return $capture->spansOfKind('phase');
    }
}
