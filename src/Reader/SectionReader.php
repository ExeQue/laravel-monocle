<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Entry;

/**
 * Paginated access to one section of an entry.
 */
final class SectionReader
{
    /**
     * @return array{section: string, total: int, filtered_from: int, returned: int, offset: int, next_offset: int|null, rows: list<array<string, mixed>>}|array{section: string, data: array<string, mixed>}
     */
    public function read(Entry $entry, string $section, RowQuery $query): array
    {
        $data = $entry->sections[$section] ?? [];

        if ($data !== [] && ! array_is_list($data)) {
            return ['section' => $section, 'data' => $data];
        }

        return ['section' => $section] + $query->apply($entry->rows($section));
    }

    /**
     * @return list<string>
     */
    public function sections(Entry $entry): array
    {
        return array_keys($entry->sections);
    }
}
