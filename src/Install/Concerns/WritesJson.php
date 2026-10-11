<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Install\Concerns;

use RuntimeException;

/**
 * Reads and writes JSON config files while keeping the indentation the file
 * already uses, so a diff only shows what changed.
 *
 * @internal
 */
trait WritesJson
{
    /**
     * @return array<string, mixed>|null null when the file is not plain JSON (e.g. it has comments)
     */
    private function readJson(string $file): ?array
    {
        if (! is_file($file)) {
            return [];
        }

        $contents = (string) file_get_contents($file);

        if (trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $file, array $data): void
    {
        $original = is_file($file) ? (string) file_get_contents($file) : '';
        $directory = dirname($file);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create [{$directory}].");
        }

        $json = $this->reindent(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $this->detectIndent($original),
        );

        if (file_put_contents($file, $json."\n") === false) {
            throw new RuntimeException("Could not write [{$file}].");
        }
    }

    private function detectIndent(string $contents): int
    {
        return preg_match('/^( +)\S/m', $contents, $matches) === 1 ? strlen($matches[1]) : 4;
    }

    private function reindent(string $json, int $indent): string
    {
        if ($indent === 4) {
            return $json;
        }

        return (string) preg_replace_callback(
            '/^( +)/m',
            fn (array $matches): string => str_repeat(' ', intdiv(strlen($matches[1]), 4) * $indent),
            $json,
        );
    }
}
