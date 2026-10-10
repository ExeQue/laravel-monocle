<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Data;

use ExeQue\Monocle\Enums\EntryType;

/**
 * Everything recorded for one unit of work: a request, a job, a command or an
 * MCP call. Lenses write into `sections`; spans form the timeline.
 */
final class Entry
{
    public const int SCHEMA_VERSION = 1;

    public ?string $name = null;

    public ?string $status = null;

    public ?string $label = null;

    public ?float $endedAt = null;

    public string $endedBy = 'normal';

    /** @var array<string, mixed> User metadata set through Monocle::meta(). */
    public array $meta = [];

    /** @var array<string, mixed> Monocle's own health data (overhead, size). */
    public array $health = [];

    /** @var array<string, Span> */
    public array $spans = [];

    /** @var array<string, array<int|string, mixed>> */
    public array $sections = [];

    public function __construct(
        public readonly string $id,
        public readonly EntryType $type,
        public readonly float $startedAt,
        public ?string $parentId = null,
        public ?string $traceId = null,
    ) {
        $this->traceId ??= $this->id;
    }

    /**
     * Milliseconds between start and end, or until now while still open.
     */
    public function duration(): float
    {
        $end = $this->endedAt ?? microtime(true);

        return round(($end - $this->startedAt) * 1000, 3);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $section): array
    {
        $rows = $this->sections[$section] ?? [];

        return array_is_list($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $section): array
    {
        $data = $this->sections[$section] ?? [];

        return array_is_list($data) ? [] : $data;
    }

    /**
     * @return list<Span>
     */
    public function spansOfKind(string $kind): array
    {
        return array_values(array_filter($this->spans, fn (Span $span): bool => $span->kind === $kind));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'status' => $this->status,
            'label' => $this->label,
            'parent_id' => $this->parentId,
            'trace_id' => $this->traceId,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'ended_by' => $this->endedBy,
            'duration' => $this->duration(),
            'meta' => $this->meta,
            'health' => $this->health,
            'spans' => array_values(array_map(fn (Span $span): array => $span->toArray(), $this->spans)),
            'sections' => $this->sections,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $entry = new self(
            id: $data['id'],
            type: EntryType::from($data['type']),
            startedAt: (float) $data['started_at'],
            parentId: $data['parent_id'] ?? null,
            traceId: $data['trace_id'] ?? null,
        );

        $entry->name = $data['name'] ?? null;
        $entry->status = $data['status'] ?? null;
        $entry->label = $data['label'] ?? null;
        $entry->endedAt = $data['ended_at'] ?? null;
        $entry->endedBy = $data['ended_by'] ?? 'normal';
        $entry->meta = $data['meta'] ?? [];
        $entry->health = $data['health'] ?? [];
        $entry->sections = $data['sections'] ?? [];

        foreach ($data['spans'] ?? [] as $span) {
            $entry->spans[$span['id']] = Span::fromArray($span);
        }

        return $entry;
    }
}
