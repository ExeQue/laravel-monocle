<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Capture;

/**
 * Side-by-side diff of two captures: summary totals, phases, categories and
 * which query shapes appeared or disappeared. Built for "did my change help?".
 *
 * @internal
 */
final class CaptureComparer
{
    public function __construct(
        private readonly SummaryBuilder $summary,
        private readonly QueryAggregator $queries,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function compare(Capture $a, Capture $b): array
    {
        $summaryA = $this->summary->build($a);
        $summaryB = $this->summary->build($b);

        return [
            'a' => $this->describe($a),
            'b' => $this->describe($b),
            'totals' => $this->diffNumeric($summaryA['totals'], $summaryB['totals']),
            'phases' => $this->diffLines($summaryA['breakdown']['phases'], $summaryB['breakdown']['phases']),
            'categories' => $this->diffLines($summaryA['breakdown']['categories'], $summaryB['breakdown']['categories']),
            'queries' => $this->diffQueries($a, $b),
            'warnings' => $this->diffMessages($summaryA['warnings'], $summaryB['warnings']),
            'errors' => $this->diffMessages($summaryA['errors'], $summaryB['errors']),
        ];
    }

    /**
     * @return array{id: string, name: string, label: string|null, started_at: string}
     */
    private function describe(Capture $capture): array
    {
        return [
            'id' => $capture->id,
            'name' => $capture->name,
            'label' => $capture->label,
            'started_at' => date('c', (int) $capture->startedAt),
        ];
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array{only_in_a: list<string>, only_in_b: list<string>}
     */
    private function diffMessages(array $a, array $b): array
    {
        return [
            'only_in_a' => array_values(array_diff($a, $b)),
            'only_in_b' => array_values(array_diff($b, $a)),
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, array{a: mixed, b: mixed, delta: float|int|null, percent: float|null}>
     */
    private function diffNumeric(array $a, array $b): array
    {
        $diff = [];

        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
            $valueA = $a[$key] ?? null;
            $valueB = $b[$key] ?? null;

            if (! is_numeric($valueA) && ! is_numeric($valueB)) {
                continue;
            }

            // A total only one side has gets no delta.
            $delta = null;
            $percent = null;

            if (is_numeric($valueA) && is_numeric($valueB)) {
                $delta = round($valueB - $valueA, 3);
                $percent = (float) $valueA === 0.0 ? null : round($delta / (float) $valueA * 100, 1);
            }

            $diff[$key] = [
                'a' => $valueA,
                'b' => $valueB,
                'delta' => $delta,
                'percent' => $percent,
            ];
        }

        return $diff;
    }

    /**
     * @param  list<array{name: string, ms: float, count: int}>  $a
     * @param  list<array{name: string, ms: float, count: int}>  $b
     * @return array<string, array{a_ms: float|null, b_ms: float|null, delta_ms: float|null, a_count: int|null, b_count: int|null}>
     */
    private function diffLines(array $a, array $b): array
    {
        $byNameA = array_column($a, null, 'name');
        $byNameB = array_column($b, null, 'name');
        $diff = [];

        foreach (array_unique([...array_keys($byNameA), ...array_keys($byNameB)]) as $name) {
            $msA = $byNameA[$name]['ms'] ?? null;
            $msB = $byNameB[$name]['ms'] ?? null;

            $diff[$name] = [
                'a_ms' => $msA,
                'b_ms' => $msB,
                'delta_ms' => $msA !== null && $msB !== null ? round($msB - $msA, 3) : null,
                'a_count' => $byNameA[$name]['count'] ?? null,
                'b_count' => $byNameB[$name]['count'] ?? null,
            ];
        }

        return $diff;
    }

    /**
     * @return array{only_in_a: list<array<string, mixed>>, only_in_b: list<array<string, mixed>>, changed: list<array<string, mixed>>}
     */
    private function diffQueries(Capture $a, Capture $b): array
    {
        $shapesA = array_column($this->queries->group($a, 'shape'), null, 'key');
        $shapesB = array_column($this->queries->group($b, 'shape'), null, 'key');

        $onlyA = [];
        $onlyB = [];
        $changed = [];

        foreach ($shapesA as $shape => $group) {
            if (! isset($shapesB[$shape])) {
                $onlyA[] = ['shape' => $shape, 'count' => $group['count'], 'total_ms' => $group['total_ms']];
            } elseif ($shapesB[$shape]['count'] !== $group['count']) {
                $changed[] = [
                    'shape' => $shape,
                    'a_count' => $group['count'],
                    'b_count' => $shapesB[$shape]['count'],
                    'a_ms' => $group['total_ms'],
                    'b_ms' => $shapesB[$shape]['total_ms'],
                ];
            }
        }

        foreach ($shapesB as $shape => $group) {
            if (! isset($shapesA[$shape])) {
                $onlyB[] = ['shape' => $shape, 'count' => $group['count'], 'total_ms' => $group['total_ms']];
            }
        }

        return ['only_in_a' => $onlyA, 'only_in_b' => $onlyB, 'changed' => $changed];
    }
}
