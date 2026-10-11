<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\LensRegistry;

/**
 * The agent's first view of a capture: where the time went, totals and
 * warnings. Lenses contribute through the Summarizable contract.
 *
 * @internal
 */
final class SummaryBuilder
{
    /** Sections whose rows carry a `duration` that counts towards a category. */
    private const array SECTION_CATEGORIES = [
        'db' => 'queries',
        'cache' => 'cache',
        'redis' => 'redis',
        'http' => 'http',
        'filesystem' => 'filesystem',
        'mail' => 'mail',
    ];

    private const array SPAN_CATEGORIES = [
        'views' => 'view',
        'ai' => 'ai.agent',
        'jobs_sync' => 'job',
    ];

    private const array PHASES = ['bootstrap', 'command', 'job', 'middleware (request)', 'routing', 'controller', 'middleware (response)', 'response', 'terminate'];

    public function __construct(
        private readonly LensRegistry $registry,
        private readonly CaptureReader $captures,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Capture $capture, bool $children = true): array
    {
        $totals = [
            'duration_ms' => $capture->duration(),
            'memory_peak_mb' => isset($capture->health['memory_peak_bytes']) ? round($capture->health['memory_peak_bytes'] / 1048576, 1) : null,
        ];
        $labels = ['duration_ms' => 'duration', 'memory_peak_mb' => 'peak memory'];
        $details = [];
        $units = ['duration_ms' => Unit::Milliseconds->value, 'memory_peak_mb' => Unit::Megabytes->value];
        $headlines = ['duration_ms', 'memory_peak_mb'];
        $groups = [];
        $warnings = [];
        $errors = [];
        $problems = [];
        $flags = [];
        $tags = [];

        foreach ($this->registry->summarizers() as $summarizer) {
            $contribution = $summarizer->summarize($capture);
            $section = $this->registry->sectionOf($summarizer);
            $totals = [...$totals, ...$contribution->totals];
            $headlines = [...$headlines, ...$contribution->headlines];
            $tags = [...$tags, ...$contribution->tags];
            $groups = [...$groups, ...array_fill_keys(array_keys($contribution->totals), $section)];
            $labels = [...$labels, ...$contribution->labels];
            $units = [...$units, ...$contribution->units];
            $warnings = [...$warnings, ...$contribution->warnings];
            $errors = [...$errors, ...$contribution->errors];
            $problems = $this->flag($problems, $section, 'warnings', count($contribution->warnings));
            $problems = $this->flag($problems, $section, 'errors', count($contribution->errors));
            $flags = [...$flags, ...$this->flags('error', $section, $contribution->errors), ...$this->flags('warning', $section, $contribution->warnings)];
        }

        // Monocle's own time during the capture, last among the headlines.
        if (isset($capture->health['overhead_ms'])) {
            $overhead = (float) $capture->health['overhead_ms'];
            $duration = (float) $capture->duration();
            $totals['monocle_ms'] = $overhead;
            $totals['monocle_percent'] = $duration > 0 ? round($overhead / $duration * 100, 1) : null;
            $labels += ['monocle_ms' => 'monocle', 'monocle_percent' => 'monocle share'];
            $units += ['monocle_ms' => Unit::Milliseconds->value, 'monocle_percent' => Unit::Percent->value];
            $headlines = [...$headlines, 'monocle_ms', 'monocle_percent'];
            $details['monocle_ms'] = (array) ($capture->health['overhead_by'] ?? []);
        }

        if (is_numeric($capture->status) && (int) $capture->status >= 500) {
            $errors[] = "Response status {$capture->status}.";
            $problems = $this->flag($problems, 'request', 'errors', 1);
            $flags[] = ['level' => 'error', 'section' => 'request', 'message' => "Response status {$capture->status}."];
        }

        foreach ((array) ($capture->health['failures'] ?? []) as $failure) {
            $warnings[] = "Monocle failed to record part of this capture: {$failure['message']} ({$failure['file']}).";
            $flags[] = ['level' => 'warning', 'section' => 'monocle', 'message' => end($warnings)];
        }

        $openSpans = (int) ($capture->health['open_spans_at_finalize'] ?? 0);

        if ($openSpans > 0) {
            $warnings[] = "{$openSpans} span(s) were never stopped and were closed at finalize.";
            $flags[] = ['level' => 'warning', 'section' => 'timeline', 'message' => end($warnings)];
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
            'children' => $children ? array_column($this->captures->children($capture->id), 'id') : [],
            'breakdown' => $this->breakdown($capture),
            'totals' => $totals,
            'labels' => $labels,
            'units' => $units,
            'headlines' => array_values(array_unique($headlines)),
            'details' => $details,
            'groups' => array_diff_key($groups, array_flip($headlines)),
            'counts' => $counts,
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
            'flags' => $this->sortFlags($flags),
            'tags' => array_values(array_unique($tags, SORT_REGULAR)),
            'problems' => $problems,
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
     * @param  'error'|'warning'  $level
     * @param  list<string>  $messages
     * @return list<array{level: string, section: string, message: string}>
     */
    private function flags(string $level, string $section, array $messages): array
    {
        return array_map(fn (string $message): array => ['level' => $level, 'section' => $section, 'message' => $message], $messages);
    }

    /**
     * Errors first, each flag once.
     *
     * @param  list<array{level: string, section: string, message: string}>  $flags
     * @return list<array{level: string, section: string, message: string}>
     */
    private function sortFlags(array $flags): array
    {
        $unique = [];

        foreach ($flags as $flag) {
            $unique[$flag['level'].'|'.$flag['message']] ??= $flag;
        }

        return [
            ...array_values(array_filter($unique, fn (array $flag): bool => $flag['level'] === 'error')),
            ...array_values(array_filter($unique, fn (array $flag): bool => $flag['level'] === 'warning')),
        ];
    }

    /**
     * Counts warnings or errors per tab.
     *
     * @param  array<string, array{warnings: int, errors: int}>  $problems
     * @return array<string, array{warnings: int, errors: int}>
     */
    private function flag(array $problems, string $section, string $kind, int $count): array
    {
        if ($count === 0) {
            return $problems;
        }

        $problems[$section] ??= ['warnings' => 0, 'errors' => 0];
        $problems[$section][$kind] += $count;

        return $problems;
    }

    /**
     * @return list<Span>
     */
    public function phases(Capture $capture): array
    {
        return $capture->spansOfKind('phase');
    }
}
