<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use Illuminate\Support\Arr;

/**
 * Free-text search over a row: every word must appear, case-insensitively,
 * in one of its values, nested values included.
 *
 * @internal
 */
final class Search
{
    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function matches(array $row, ?string $text): bool
    {
        $words = preg_split('/\s+/', mb_strtolower(trim((string) $text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return true;
        }

        $haystack = mb_strtolower(implode("\n", array_map(
            fn (mixed $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) (is_scalar($value) ? $value : ''),
            Arr::dot($row),
        )));

        foreach ($words as $word) {
            if (! str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }
}
