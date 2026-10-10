<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Entry;
use ExeQue\Monocle\LensRegistry;

/**
 * One array-in, array-out API shared by the MCP tools, the artisan commands
 * and any future UI, so every front shows identical data.
 */
final class Presenter
{
    public function __construct(
        private readonly EntryReader $entries,
        private readonly SectionReader $sections,
        private readonly SummaryBuilder $summary,
        private readonly TimelineBuilder $timeline,
        private readonly QueryAggregator $queries,
        private readonly LensRegistry $registry,
    ) {
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function list(array $args): array
    {
        return $this->entries->list(Filter::fromArray($args));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function show(array $args): array
    {
        $entry = $this->resolve($args);

        if ($entry === null) {
            return $this->notFound($args);
        }

        $requested = array_values(array_filter(array_map('strval', (array) ($args['sections'] ?? ['summary']))));
        $query = RowQuery::fromArray(['limit' => $args['limit'] ?? 25, 'verbose' => $args['verbose'] ?? false]);
        $result = ['id' => $entry->id, 'available_sections' => $this->availableSections($entry)];

        foreach ($requested as $section) {
            $result[$section] = match ($section) {
                'summary' => $this->summary->build($entry),
                'timeline' => $this->timeline->build($entry, (array) ($args['kinds'] ?? []), $query->verbose),
                default => $this->sections->read($entry, $section, $query),
            };
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function rows(array $args): array
    {
        $entry = $this->resolve($args);

        if ($entry === null) {
            return $this->notFound($args);
        }

        $section = (string) ($args['section'] ?? '');

        if ($section === 'timeline') {
            $rows = $this->timeline->build($entry, (array) ($args['kinds'] ?? []), filter_var($args['verbose'] ?? false, FILTER_VALIDATE_BOOL));

            return ['id' => $entry->id, 'section' => 'timeline'] + RowQuery::fromArray($args)->apply($rows);
        }

        return ['id' => $entry->id] + $this->sections->read($entry, $section, RowQuery::fromArray($args));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function queries(array $args): array
    {
        $entry = $this->resolve($args);

        if ($entry === null) {
            return $this->notFound($args);
        }

        $groups = $this->queries->group(
            $entry,
            (string) ($args['group_by'] ?? 'shape'),
            (string) ($args['sort'] ?? 'total_ms'),
        );

        return ['id' => $entry->id, 'group_by' => $args['group_by'] ?? 'shape'] + RowQuery::fromArray(['offset' => $args['offset'] ?? 0, 'limit' => $args['limit'] ?? 25])->apply($groups);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function trace(array $args): array
    {
        $entry = $this->resolve($args);

        if ($entry === null) {
            return $this->notFound($args);
        }

        return [
            'id' => $entry->id,
            'trace_id' => $entry->traceId,
            'entries' => $this->entries->trace($entry->traceId ?? $entry->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clear(): array
    {
        $this->entries->clear();

        return ['cleared' => true];
    }

    /**
     * Every section the agent can ask for, with what it contains and how many
     * rows the entry holds, so it can decide where to look next.
     *
     * @return list<array{key: string, description: string, rows: int|null}>
     */
    private function availableSections(Entry $entry): array
    {
        $descriptions = $this->registry->descriptions();

        $sections = [
            ['key' => 'summary', 'description' => 'Time breakdown per phase and category, totals, warnings (slow/duplicate/N+1 queries, slow middleware), trace links and Monocle health.', 'rows' => null],
            ['key' => 'timeline', 'description' => 'The span tree: request phases, each middleware (before/after $next), the derived controller span, transactions and spans added via Monocle::span(). Filter with kinds.', 'rows' => count($entry->spans)],
        ];

        foreach ($entry->sections as $key => $data) {
            $sections[] = [
                'key' => (string) $key,
                'description' => $descriptions[$key] ?? 'Custom section.',
                'rows' => array_is_list($data) ? count($data) : null,
            ];
        }

        foreach ($descriptions as $key => $description) {
            if (! array_key_exists($key, $entry->sections)) {
                $sections[] = ['key' => $key, 'description' => $description, 'rows' => 0];
            }
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resolve(array $args): ?Entry
    {
        return $this->entries->find((string) ($args['id'] ?? 'latest'));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function notFound(array $args): array
    {
        $id = (string) ($args['id'] ?? 'latest');

        return [
            'error' => $id === 'latest'
                ? 'No entries recorded yet. Make a request to the application first.'
                : "Entry [{$id}] not found. It may have been evicted from the ring buffer; use monocle-list to see what is stored.",
        ];
    }
}
