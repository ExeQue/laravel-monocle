<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Contracts\Lens;
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
            'memory_peak_mb' => $this->memoryPeakMb($capture),
        ];
        $labels = ['duration_ms' => 'duration', 'memory_peak_mb' => 'peak memory'];
        $units = ['duration_ms' => Unit::Milliseconds->value, 'memory_peak_mb' => Unit::Megabytes->value];
        $headlines = ['duration_ms', 'memory_peak_mb'];
        $details = [];
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
            $labels = [...$labels, ...$contribution->labels];
            $units = [...$units, ...$contribution->units];
            $headlines = [...$headlines, ...$contribution->headlines];
            $groups = [...$groups, ...array_fill_keys(array_keys($contribution->totals), $section)];
            $tags = [...$tags, ...$contribution->tags];

            $warnings = [...$warnings, ...$contribution->warnings];
            $errors = [...$errors, ...$contribution->errors];
            $problems = $this->flag($problems, $section, 'warnings', count($contribution->warnings));
            $problems = $this->flag($problems, $section, 'errors', count($contribution->errors));
            $flags = [...$flags, ...$this->flags('error', $section, $contribution->errors)];
            $flags = [...$flags, ...$this->flags('warning', $section, $contribution->warnings)];
        }

        $overheadByLens = (array) ($capture->health['overhead_by'] ?? []);

        // Monocle's own time during the capture, last among the headlines.
        if (isset($capture->health['overhead_ms'])) {
            $overhead = (float) $capture->health['overhead_ms'];
            $duration = (float) $capture->duration();

            $totals['monocle_ms'] = $overhead;
            $totals['monocle_percent'] = $duration > 0 ? round($overhead / $duration * 100, 1) : null;
            $labels += ['monocle_ms' => 'monocle', 'monocle_percent' => 'monocle share'];
            $units += ['monocle_ms' => Unit::Milliseconds->value, 'monocle_percent' => Unit::Percent->value];
            $headlines = [...$headlines, 'monocle_ms', 'monocle_percent'];
            $details['monocle_ms'] = $overheadByLens;
        }

        if ($this->isServerError($capture)) {
            $message = "Response status {$capture->status}.";
            $errors[] = $message;
            $problems = $this->flag($problems, 'request', 'errors', 1);
            $flags[] = ['level' => 'error', 'section' => 'request', 'message' => $message];
        }

        foreach ((array) ($capture->health['failures'] ?? []) as $failure) {
            $message = "Monocle failed to record part of this capture: {$failure['message']} ({$failure['file']}).";
            $warnings[] = $message;
            $flags[] = ['level' => 'warning', 'section' => 'monocle', 'message' => $message];
        }

        $openSpans = (int) ($capture->health['open_spans_at_finalize'] ?? 0);

        if ($openSpans > 0) {
            $message = "{$openSpans} span(s) were never stopped and were closed at finalize.";
            $warnings[] = $message;
            $flags[] = ['level' => 'warning', 'section' => 'timeline', 'message' => $message];
        }

        $childIds = $children ? array_column($this->captures->children($capture->id), 'id') : [];

        return [
            'id' => $capture->id,
            'type' => $capture->type->value,
            'name' => $capture->name,
            'status' => $capture->status,
            'label' => $capture->label,
            'started_at' => date('c', (int) $capture->startedAt),
            'ended_by' => $capture->endedBy,
            'parent_id' => $capture->parentId,
            // A request asked to join a capture this store does not hold (trace.browser.enabled).
            'missing_parent_id' => $capture->section('request')['missing_parent_id'] ?? null,
            'trace_id' => $capture->traceId,
            'linked_via' => $capture->linkedVia,
            'children' => $childIds,
            'breakdown' => $this->breakdown($capture),
            'totals' => $totals,
            'labels' => $labels,
            'units' => $units,
            'headlines' => array_values(array_unique($headlines)),
            'details' => $details,
            'monocle_by_section' => $this->overheadBySection($overheadByLens),
            // Headline totals are shown on their own; only the rest are grouped per section.
            'groups' => array_diff_key($groups, array_flip($headlines)),
            'counts' => $this->rowCounts($capture),
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
            'flags' => $this->sortFlags($flags),
            'tags' => array_values(array_unique($tags, SORT_REGULAR)),
            'problems' => $problems,
            'meta' => $capture->meta,
            'health' => $capture->health + ['sections' => array_keys($capture->sections)],
        ];
    }

    private function memoryPeakMb(Capture $capture): ?float
    {
        if (! isset($capture->health['memory_peak_bytes'])) {
            return null;
        }

        return round($capture->health['memory_peak_bytes'] / 1048576, 1);
    }

    private function isServerError(Capture $capture): bool
    {
        if (! is_numeric($capture->status)) {
            return false;
        }

        return (int) $capture->status >= 500;
    }

    /**
     * Row count per section that holds rows (a list), not a single record.
     *
     * @return array<string, int>
     */
    private function rowCounts(Capture $capture): array
    {
        return collect($capture->sections)
            ->filter(fn (array $rows): bool => array_is_list($rows))
            ->map(fn (array $rows): int => count($rows))
            ->all();
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
            [$ms, $count] = $this->phaseTime($capture, $phase);

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

            $ms = collect($rows)->sum(fn (array $row): float => (float) ($row['duration'] ?? 0));

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
     * Middleware and the controller are spans of their own kind; every other
     * phase is a `phase` span named after it.
     *
     * @return array{0: float, 1: int}
     */
    private function phaseTime(Capture $capture, string $phase): array
    {
        return match ($phase) {
            'middleware (request)' => $this->spanTime($capture, 'middleware.request', null),
            'middleware (response)' => $this->spanTime($capture, 'middleware.response', null),
            'controller' => $this->spanTime($capture, 'controller', null),
            default => $this->spanTime($capture, 'phase', $phase),
        };
    }

    /**
     * @return array{0: float, 1: int}
     */
    private function spanTime(Capture $capture, string $kind, ?string $name, bool $topLevelOnly = false): array
    {
        $spans = collect($capture->spans)
            ->filter(fn (Span $span): bool => $span->kind === $kind && ($name === null || $span->name === $name))
            // A view rendered inside another view is already part of the outer one's time.
            ->reject(fn (Span $span): bool => $topLevelOnly && $this->hasParentOfSameKind($span, $capture));

        return [round($spans->sum(fn (Span $span): float => $span->duration() ?? 0.0), 3), $spans->count()];
    }

    private function hasParentOfSameKind(Span $span, Capture $capture): bool
    {
        if ($span->parentId === null) {
            return false;
        }

        $parent = $capture->spans[$span->parentId] ?? null;

        if ($parent === null) {
            return false;
        }

        return $parent->kind === $span->kind;
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
        $unique = collect($flags)->uniqueStrict(fn (array $flag): string => $flag['level'].'|'.$flag['message']);

        return [...$unique->whereStrict('level', 'error')->all(), ...$unique->whereStrict('level', 'warning')->all()];
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

    /**
     * Monocle's own time per section: a lens that adds to another lens's
     * section counts on that section, so each tab shows what recording it took.
     *
     * @param  array<string, float>  $byLens
     * @return array<string, float>
     */
    private function overheadBySection(array $byLens): array
    {
        $sections = collect($this->registry->booted())
            ->mapWithKeys(fn (Lens $lens): array => [$lens->key() => $this->registry->sectionOf($lens)])
            ->all();

        $bySection = [];

        foreach ($byLens as $owner => $ms) {
            $section = $sections[$owner] ?? $owner;
            $bySection[$section] = round(($bySection[$section] ?? 0) + $ms, 3);
        }

        return $bySection;
    }
}
