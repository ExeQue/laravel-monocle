<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Storage;

use Closure;
use ExeQue\Monocle\Contracts\Store;
use ExeQue\Monocle\Data\Capture;
use InvalidArgumentException;
use RuntimeException;

/**
 * Default store: one {id}.monocle.json file per capture plus an index.monocle.json holding summary
 * rows newest first. Works across web, worker and MCP processes with no
 * configuration. The index is rewritten under an exclusive lock.
 *
 * ponytail: index is a single JSON document; switch to jsonl/append if limits
 * in the thousands ever make rewriting it measurable.
 *
 * @internal
 */
final class FileStore implements Store
{
    public const string SUFFIX = '.monocle.json';

    /**
     * @param  string  $within  directory the store must live inside - the application's storage path; a store that wants to live elsewhere is a custom Store, with the responsibility that comes with it
     */
    public function __construct(
        private readonly string $path,
        private readonly int $limit,
        private readonly string $within,
    ) {
        // A relative or empty path would make the store operate on the working
        // directory; refuse it rather than ever deleting files there.
        if (trim($this->path, '/') === '' || ! str_starts_with($this->path, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("Monocle file storage needs an absolute path, got [{$this->path}].");
        }

        if (! self::isInside($this->path, $this->within)) {
            throw new InvalidArgumentException("Monocle file storage must live inside [{$this->within}], got [{$this->path}].");
        }
    }

    /**
     * Lexical containment check on normalized paths, so it also holds before
     * the directories exist.
     */
    public static function isInside(string $path, string $root): bool
    {
        $path = self::normalize($path);
        $root = self::normalize($root);

        if ($path === $root) {
            return true;
        }

        return str_starts_with($path, rtrim($root, '/').'/');
    }

    /**
     * Resolves "." and ".." without touching the filesystem.
     */
    private static function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }

    public function put(Capture $capture): void
    {
        $this->ensureDirectory();

        $this->write($this->capturePath($capture->id), json_encode($capture->toArray(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));

        $this->withLockedIndex(function (array $index) use ($capture): array {
            array_unshift($index, Index::summarize($capture));

            foreach (array_splice($index, max(1, $this->limit)) as $evicted) {
                @unlink($this->capturePath($evicted['id']));
            }

            return $index;
        });
    }

    public function find(string $id): ?Capture
    {
        if (! $this->isValidId($id)) {
            return null;
        }

        $path = $this->capturePath($id);

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? Capture::fromArray($data) : null;
    }

    public function index(): array
    {
        return $this->readIndex();
    }

    public function clear(): void
    {
        if (! is_dir($this->path)) {
            return;
        }

        // Only what the index knows about is ours to delete, and only files
        // carrying Monocle's own suffix.
        foreach ($this->readIndex() as $row) {
            $id = (string) ($row['id'] ?? '');

            if ($this->isValidId($id) && str_ends_with($this->capturePath($id), self::SUFFIX)) {
                @unlink($this->capturePath($id));
            }
        }

        @unlink($this->indexPath());
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param  Closure(list<array<string, mixed>>): list<array<string, mixed>>  $mutate
     */
    private function withLockedIndex(Closure $mutate): void
    {
        $lock = fopen($this->path.DIRECTORY_SEPARATOR.'index.monocle.lock', 'c');

        if ($lock === false) {
            throw new RuntimeException("Monocle could not open the index lock in [{$this->path}].");
        }

        try {
            flock($lock, LOCK_EX);

            $index = $mutate($this->readIndex());

            $this->write($this->indexPath(), json_encode(array_values($index), JSON_THROW_ON_ERROR));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readIndex(): array
    {
        if (! is_file($this->indexPath())) {
            return [];
        }

        $contents = @file_get_contents($this->indexPath());
        $index = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($index) ? array_values($index) : [];
    }

    private function write(string $path, string $contents): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($temporary, $contents) === false || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Monocle could not write [{$path}].");
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->path)) {
            return;
        }

        if (! @mkdir($this->path, 0775, true) && ! is_dir($this->path)) {
            throw new RuntimeException("Monocle could not create the storage directory [{$this->path}].");
        }

        file_put_contents($this->path.DIRECTORY_SEPARATOR.'.gitignore', "*\n!.gitignore\n");
    }

    /**
     * Ids are uuids (or simple tokens in tests); anything else - slashes,
     * dots, traversal - never becomes a path.
     */
    private function isValidId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $id) === 1;
    }

    private function capturePath(string $id): string
    {
        if (! $this->isValidId($id)) {
            throw new InvalidArgumentException("Monocle capture ids may only contain letters, digits, dashes and underscores, got [{$id}].");
        }

        $path = $this->path.DIRECTORY_SEPARATOR.$id.self::SUFFIX;
        $root = realpath($this->path);

        // Belt and braces: the resolved file must live inside the storage directory.
        if ($root !== false && ! str_starts_with((string) realpath(dirname($path)), $root)) {
            // Unreachable once the id is validated: dirname($path) is the store path itself.
            // @codeCoverageIgnoreStart
            throw new InvalidArgumentException("Monocle refused to touch [{$path}] outside of [{$root}].");
            // @codeCoverageIgnoreEnd
        }

        return $path;
    }

    private function indexPath(): string
    {
        return $this->path.DIRECTORY_SEPARATOR.'index'.self::SUFFIX;
    }
}
