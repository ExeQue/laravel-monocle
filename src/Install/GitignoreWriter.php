<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

use RuntimeException;

/**
 * Appends paths to a .gitignore under one comment, skipping paths an
 * equivalent line already covers.
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
        $missing = [];

        foreach ((array) $entries as $entry) {
            $line = '/'.ltrim($entry, '/');

            if (! in_array($this->normalize($line), $present, true) && ! in_array($line, $missing, true)) {
                $missing[] = $line;
            }
        }

        if ($missing === []) {
            return 'unchanged';
        }

        if ($dryRun) {
            return 'added';
        }

        $contents = $existing === '' || str_ends_with($existing, "\n") ? $existing : $existing."\n";
        $contents .= $existing === '' ? '' : "\n";

        if (file_put_contents($file, $contents.self::COMMENT."\n".implode("\n", $missing)."\n") === false) {
            throw new RuntimeException("Could not write [{$file}].");
        }

        return 'added';
    }

    private function normalize(string $line): string
    {
        return trim(trim($line), '/');
    }
}
