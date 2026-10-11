<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Storage;

use ExeQue\Monocle\Data\Capture;

/**
 * Builds the summary row kept in the store index so listing, tracing and
 * filtering never need to load full captures.
 */
final class Index
{
    /**
     * @return array<string, mixed>
     */
    public static function summarize(Capture $capture): array
    {
        $counts = [];

        foreach ($capture->sections as $section => $rows) {
            if (array_is_list($rows)) {
                $counts[$section] = count($rows);
            }
        }

        return [
            'id' => $capture->id,
            'parent_id' => $capture->parentId,
            'trace_id' => $capture->traceId,
            'type' => $capture->type->value,
            'name' => $capture->name,
            'route' => $capture->section('request')['route']['name'] ?? null,
            'status' => $capture->status,
            'label' => $capture->label,
            'duration' => $capture->duration(),
            'started_at' => $capture->startedAt,
            'ended_by' => $capture->endedBy,
            'counts' => $counts,
            'has_errors' => self::hasErrors($capture),
            'warnings' => (int) ($capture->health['warnings'] ?? 0),
            'errors' => (int) ($capture->health['errors'] ?? 0),
        ];
    }

    private static function hasErrors(Capture $capture): bool
    {
        if (is_numeric($capture->status) && (int) $capture->status >= 500) {
            return true;
        }

        if (in_array($capture->status, ['failed', 'error'], true)) {
            return true;
        }

        if ((int) ($capture->health['errors'] ?? 0) > 0) {
            return true;
        }

        return $capture->rows('exceptions') !== [];
    }
}
