<?php

declare(strict_types=1);

namespace ExeQue\Monocle;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Reader\CaptureComparer;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use ExeQue\Monocle\Reader\QueryAggregator;
use ExeQue\Monocle\Reader\RowQuery;
use ExeQue\Monocle\Reader\SectionReader;
use ExeQue\Monocle\Reader\SummaryBuilder;
use ExeQue\Monocle\Reader\TimelineBuilder;

/**
 * One array-in, array-out API shared by the MCP tools, the artisan commands
 * and any future UI, so every front shows identical data.
 */
final class Viewfinder
{
    public function __construct(
        private readonly CaptureReader $captures,
        private readonly SectionReader $sections,
        private readonly SummaryBuilder $summary,
        private readonly TimelineBuilder $timeline,
        private readonly QueryAggregator $queries,
        private readonly LensRegistry $registry,
        private readonly CaptureComparer $comparer,
    ) {
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function list(array $args): array
    {
        return $this->captures->list(Filter::fromArray($args));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function show(array $args): array
    {
        $capture = $this->resolve($args);

        if ($capture === null) {
            return $this->notFound($args);
        }

        $requested = array_values(array_filter(array_map('strval', (array) ($args['sections'] ?? ['summary']))));
        $query = RowQuery::fromArray(['limit' => $args['limit'] ?? 25, 'verbose' => $args['verbose'] ?? false]);
        $result = ['id' => $capture->id, 'available_sections' => $this->availableSections($capture)];

        foreach ($requested as $section) {
            $result[$section] = match ($section) {
                'summary' => $this->summary->build($capture),
                'timeline' => $this->timeline->build($capture, (array) ($args['kinds'] ?? []), $query->verbose),
                default => $this->sections->read($capture, $section, $query),
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
        $capture = $this->resolve($args);

        if ($capture === null) {
            return $this->notFound($args);
        }

        $section = (string) ($args['section'] ?? '');

        if ($section === 'timeline') {
            $rows = $this->timeline->build($capture, (array) ($args['kinds'] ?? []), filter_var($args['verbose'] ?? false, FILTER_VALIDATE_BOOL));

            return ['id' => $capture->id, 'section' => 'timeline'] + RowQuery::fromArray($args)->apply($rows);
        }

        return ['id' => $capture->id] + $this->sections->read($capture, $section, RowQuery::fromArray($args));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function queries(array $args): array
    {
        $capture = $this->resolve($args);

        if ($capture === null) {
            return $this->notFound($args);
        }

        $groups = $this->queries->group(
            $capture,
            (string) ($args['group_by'] ?? 'shape'),
            (string) ($args['sort'] ?? 'total_ms'),
        );

        return ['id' => $capture->id, 'group_by' => $args['group_by'] ?? 'shape'] + RowQuery::fromArray(['offset' => $args['offset'] ?? 0, 'limit' => $args['limit'] ?? 25])->apply($groups);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function trace(array $args): array
    {
        $capture = $this->resolve($args);

        if ($capture === null) {
            return $this->notFound($args);
        }

        return [
            'id' => $capture->id,
            'trace_id' => $capture->traceId,
            'captures' => $this->captures->trace($capture->traceId ?? $capture->id),
        ];
    }

    /**
     * Compare two captures given by id or label; "latest" is allowed for b.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function compare(array $args): array
    {
        $a = $this->resolveByIdOrLabel((string) ($args['a'] ?? ''));
        $b = $this->resolveByIdOrLabel((string) ($args['b'] ?? 'latest'));

        if ($a === null) {
            return ['error' => sprintf('Capture [%s] not found by id or label.', $args['a'] ?? '')];
        }

        if ($b === null) {
            return ['error' => sprintf('Capture [%s] not found by id or label.', $args['b'] ?? 'latest')];
        }

        return $this->comparer->compare($a, $b);
    }

    /**
     * @return array<string, mixed>
     */
    public function clear(): array
    {
        $this->captures->clear();

        return ['cleared' => true];
    }

    /**
     * Every section the agent can ask for, with what it contains and how many
     * rows the capture holds, so it can decide where to look next.
     *
     * @return list<array{key: string, description: string, rows: int|null}>
     */
    private function availableSections(Capture $capture): array
    {
        $descriptions = $this->registry->descriptions();

        $sections = [
            ['key' => 'summary', 'description' => 'Time breakdown per phase and category, totals, warnings (slow/duplicate/N+1 queries, slow middleware), errors (exceptions, failed calls and jobs), trace links and Monocle health.', 'rows' => null],
            ['key' => 'timeline', 'description' => 'The span tree: request phases, each middleware (request and response side), the derived controller span, transactions and spans added via Monocle::span(). Filter with kinds.', 'rows' => count($capture->spans)],
        ];

        foreach ($capture->sections as $key => $data) {
            $sections[] = [
                'key' => (string) $key,
                'description' => $descriptions[$key] ?? 'Custom section.',
                'rows' => array_is_list($data) ? count($data) : null,
            ];
        }

        foreach ($descriptions as $key => $description) {
            if (! array_key_exists($key, $capture->sections)) {
                $sections[] = ['key' => $key, 'description' => $description, 'rows' => 0];
            }
        }

        return $sections;
    }

    private function resolveByIdOrLabel(string $reference): ?Capture
    {
        if ($reference === '') {
            return null;
        }

        $capture = $this->captures->find($reference);

        if ($capture !== null) {
            return $capture;
        }

        $labelled = $this->captures->list(Filter::fromArray(['label' => $reference, 'limit' => 1]))['rows'][0] ?? null;

        return $labelled === null ? null : $this->captures->find($labelled['id']);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resolve(array $args): ?Capture
    {
        return $this->captures->find((string) ($args['id'] ?? 'latest'));
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
                ? 'No captures recorded yet. Make a request to the application first.'
                : "Capture [{$id}] not found. It may have been evicted from the ring buffer; use monocle-list to see what is stored.",
        ];
    }
}
