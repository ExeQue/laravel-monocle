<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\GraphQL\Lighthouse;

/**
 * Finds where a field is defined in Lighthouse's schema files, following
 * #import lines the way Lighthouse stitches them. Lighthouse parses the
 * stitched schema without locations, so the files are read again.
 *
 * @internal
 */
final class SchemaFiles
{
    /** @var array<string, array<string, string>> Root file => "Type.field" => path:line, per process. */
    private static array $found = [];

    /**
     * The root schema file, inside the application relative to it, or null
     * when there is none.
     */
    public function root(): ?string
    {
        $root = config('lighthouse.schema_path');

        return is_string($root) && is_file($root) ? $this->relative((string) realpath($root)) : null;
    }

    /**
     * The file and line where a field of the type is defined, or null.
     */
    public function find(string $type, string $field): ?string
    {
        $root = config('lighthouse.schema_path');

        if (! is_string($root) || ! is_file($root)) {
            return null;
        }

        // ponytail: kept for the process; a long-running worker (Octane) misses schema edits until it restarts.
        self::$found[$root] ??= $this->scan($root);

        return self::$found[$root]["{$type}.{$field}"] ?? null;
    }

    /**
     * Every field of every type and type extension, by "Type.field".
     *
     * ponytail: a line scan, not a parser: a field is a line inside a type
     * block that starts with its name and a "(" or ":". Enough for SDL as
     * people write it.
     *
     * @return array<string, string>
     */
    private function scan(string $root): array
    {
        $found = [];

        foreach ($this->files($root) as $file) {
            $type = null;
            $description = false;

            foreach (file($file) ?: [] as $index => $line) {
                if (substr_count($line, '"""') % 2 === 1) {
                    $description = ! $description;

                    continue;
                }

                if ($description) {
                    continue;
                }

                if (preg_match('/^\s*(?:extend\s+)?type\s+(\w+)/', $line, $match) === 1) {
                    $type = $match[1];
                } elseif (preg_match('/^\s*}/', $line) === 1) {
                    $type = null;
                } elseif ($type !== null && preg_match('/^\s*(\w+)\s*[(:]/', $line, $match) === 1) {
                    $found["{$type}.{$match[1]}"] ??= $this->relative($file).':'.($index + 1);
                }
            }
        }

        return $found;
    }

    /**
     * The root file and every file it imports, recursively.
     *
     * @return list<string>
     */
    private function files(string $path, array $seen = []): array
    {
        $files = [$path];

        foreach (file($path) ?: [] as $line) {
            if (! str_starts_with(trim($line), '#import ')) {
                continue;
            }

            foreach (glob(dirname($path).'/'.trim(substr(trim($line), 8))) ?: [] as $import) {
                $import = realpath($import);

                if ($import !== false && ! in_array($import, [...$seen, ...$files], true)) {
                    $files = [...$files, ...$this->files($import, [...$seen, ...$files])];
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function relative(string $file): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
