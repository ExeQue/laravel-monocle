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

        $values = array_map(self::searchable(...), Arr::dot($row));
        $haystack = mb_strtolower(implode("\n", $values));

        foreach ($words as $word) {
            if (! str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Booleans read as "true"/"false" so they can be searched for; other
     * non-scalars (empty arrays left by Arr::dot) read as nothing.
     */
    private static function searchable(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (! is_scalar($value)) {
            return '';
        }

        return (string) $value;
    }
}
