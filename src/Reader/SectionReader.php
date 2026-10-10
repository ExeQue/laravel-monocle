<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Reader;

use ExeQue\Monocle\Data\Capture;

/**
 * Paginated access to one section of a capture.
 */
final class SectionReader
{
    /**
     * @return array{section: string, total: int, filtered_from: int, returned: int, offset: int, next_offset: int|null, rows: list<array<string, mixed>>}|array{section: string, data: array<string, mixed>}
     */
    public function read(Capture $capture, string $section, RowQuery $query): array
    {
        $data = $capture->sections[$section] ?? [];

        if ($data !== [] && ! array_is_list($data)) {
            return ['section' => $section, 'data' => $data];
        }

        return ['section' => $section] + $query->apply($capture->rows($section));
    }

    /**
     * @return list<string>
     */
    public function sections(Capture $capture): array
    {
        return array_keys($capture->sections);
    }
}
