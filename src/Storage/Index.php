<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Storage;

use ExeQue\Monocle\Data\Entry;

/**
 * Builds the summary row kept in the store index so listing, tracing and
 * filtering never need to load full entries.
 */
final class Index
{
    /**
     * @return array<string, mixed>
     */
    public static function summarize(Entry $entry): array
    {
        $counts = [];

        foreach ($entry->sections as $section => $rows) {
            if (array_is_list($rows)) {
                $counts[$section] = count($rows);
            }
        }

        return [
            'id' => $entry->id,
            'parent_id' => $entry->parentId,
            'trace_id' => $entry->traceId,
            'type' => $entry->type->value,
            'name' => $entry->name,
            'status' => $entry->status,
            'label' => $entry->label,
            'duration' => $entry->duration(),
            'started_at' => $entry->startedAt,
            'ended_by' => $entry->endedBy,
            'counts' => $counts,
            'has_errors' => self::hasErrors($entry),
        ];
    }

    private static function hasErrors(Entry $entry): bool
    {
        if (is_numeric($entry->status) && (int) $entry->status >= 500) {
            return true;
        }

        if (in_array($entry->status, ['failed', 'error'], true)) {
            return true;
        }

        return $entry->rows('exceptions') !== [];
    }
}
