<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

/**
 * Trims what the Viewfinder returns down to what an agent reads, for the MCP
 * tools and the commands' JSON output. The dashboard and the commands' tables
 * keep the full data: labels, units and groups are there to lay it out.
 *
 * @internal
 */
final class AgentView
{
    /** Lenses listed by their own recording time; the rest is in monocle_ms. */
    private const int TOP_OVERHEAD = 5;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function show(array $data): array
    {
        if (isset($data['available_sections'])) {
            $data['available_sections'] = self::sections($data['available_sections']);
        }

        if (isset($data['summary'])) {
            $data['summary'] = self::summary($data['summary']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function list(array $data): array
    {
        if (isset($data['rows'])) {
            $data['rows'] = array_map(self::indexRow(...), $data['rows']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function trace(array $data): array
    {
        if (isset($data['captures'])) {
            // Every capture in a trace shares the trace_id given once at the top.
            $data['captures'] = array_map(fn (array $row): array => array_diff_key(self::indexRow($row), ['trace_id' => true]), $data['captures']);
        }

        return $data;
    }

    /**
     * Section => row count ("record" for a section holding one record), leaving
     * out sections without data; the server instructions describe every section.
     *
     * @param  list<array{key: string, description: string, rows: int|null}>  $sections
     * @return array<string, int|string>
     */
    private static function sections(array $sections): array
    {
        $counts = [];

        foreach ($sections as $section) {
            if ($section['key'] === 'summary') {
                continue;
            }

            if ($section['rows'] === 0) {
                continue;
            }

            $counts[$section['key']] = $section['rows'] ?? 'record';
        }

        return $counts;
    }

    /**
     * Flags carry the warnings and errors with their section, and
     * available_sections the row counts, so their other copies go.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private static function summary(array $summary): array
    {
        $health = (array) ($summary['health'] ?? []);
        $overhead = (array) ($health['overhead_by'] ?? []);
        arsort($overhead);

        $summary['monocle_top'] = array_slice($overhead, 0, self::TOP_OVERHEAD, true);
        $summary['health'] = array_filter([
            'open_spans_at_finalize' => $health['open_spans_at_finalize'] ?? null,
            'failures' => $health['failures'] ?? null,
            'finalize_ms' => $health['finalize_ms'] ?? null,
        ]);

        $summary = array_diff_key($summary, array_flip([
            'labels', 'units', 'groups', 'details', 'monocle_by_section', 'counts', 'warnings', 'errors', 'problems',
        ]));

        return self::withoutEmpty($summary);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function indexRow(array $row): array
    {
        // A root capture starts its own trace.
        if (($row['trace_id'] ?? null) === ($row['id'] ?? null)) {
            unset($row['trace_id']);
        }

        return self::withoutEmpty($row);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withoutEmpty(array $data): array
    {
        return array_filter($data, fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
