<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install;

use RuntimeException;

/**
 * Appends a path to a .gitignore unless an equivalent line is already there.
 */
final class GitignoreWriter
{
    public const string COMMENT = '# Monocle profiler captures (see config/monocle.php)';

    /**
     * @return 'added'|'unchanged'
     */
    public function add(string $file, string $line, bool $dryRun = false): string
    {
        $line = '/'.trim($line, '/').'/';
        $existing = is_file($file) ? (string) file_get_contents($file) : '';

        foreach (preg_split('/\R/', $existing) ?: [] as $current) {
            if ($this->normalize($current) === $this->normalize($line)) {
                return 'unchanged';
            }
        }

        if ($dryRun) {
            return 'added';
        }

        $contents = $existing === '' || str_ends_with($existing, "\n") ? $existing : $existing."\n";
        $contents .= $existing === '' ? '' : "\n";

        if (file_put_contents($file, $contents.self::COMMENT."\n".$line."\n") === false) {
            throw new RuntimeException("Could not write [{$file}].");
        }

        return 'added';
    }

    private function normalize(string $line): string
    {
        return trim(trim($line), '/');
    }
}
