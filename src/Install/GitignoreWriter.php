<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

use RuntimeException;

/**
 * Appends paths to a .gitignore under one comment, skipping paths an
 * equivalent line already covers.
 *
 * @internal
 */
final class GitignoreWriter
{
    public const string COMMENT = '# Monocle (profiler captures and personal editor settings)';

    /**
     * @param  string|list<string>  $entries  paths relative to the project root; a trailing slash marks a directory
     * @return 'added'|'unchanged'
     */
    public function add(string $file, string|array $entries, bool $dryRun = false): string
    {
        $existing = is_file($file) ? (string) file_get_contents($file) : '';
        $present = array_map($this->normalize(...), preg_split('/\R/', $existing) ?: []);
        $missing = collect((array) $entries)
            // Anchored to the root, so "storage/monocle/" does not also match nested folders.
            ->map(fn (string $entry): string => '/'.ltrim($entry, '/'))
            ->reject(fn (string $line): bool => in_array($this->normalize($line), $present, true))
            ->unique()
            ->values()
            ->all();

        if ($missing === []) {
            return 'unchanged';
        }

        if ($dryRun) {
            return 'added';
        }

        $block = self::COMMENT."\n".implode("\n", $missing)."\n";

        if (file_put_contents($file, $this->withTrailingBlankLine($existing).$block) === false) {
            throw new RuntimeException("Could not write [{$file}].");
        }

        return 'added';
    }

    /**
     * The existing contents ending in a blank line, to set the new block apart.
     */
    private function withTrailingBlankLine(string $existing): string
    {
        if ($existing === '') {
            return '';
        }

        if (! str_ends_with($existing, "\n")) {
            $existing .= "\n";
        }

        return $existing."\n";
    }

    private function normalize(string $line): string
    {
        return trim(trim($line), '/');
    }
}
