<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Storage;

use Closure;
use ExeQue\Monocle\Contracts\Store;
use ExeQue\Monocle\Data\Capture;
use RuntimeException;

/**
 * Default store: one JSON file per capture plus an index.json holding summary
 * rows newest first. Works across web, worker and MCP processes with no
 * configuration. The index is rewritten under an exclusive lock.
 *
 * ponytail: index is a single JSON document; switch to jsonl/append if limits
 * in the thousands ever make rewriting it measurable.
 */
final class FileStore implements Store
{
    public function __construct(
        private readonly string $path,
        private readonly int $limit,
    ) {
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

        foreach (glob($this->path . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            @unlink($file);
        }
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
        $lock = fopen($this->path . DIRECTORY_SEPARATOR . 'index.lock', 'c');

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
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

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

        file_put_contents($this->path . DIRECTORY_SEPARATOR . '.gitignore', "*\n!.gitignore\n");
    }

    private function capturePath(string $id): string
    {
        return $this->path . DIRECTORY_SEPARATOR . basename($id) . '.json';
    }

    private function indexPath(): string
    {
        return $this->path . DIRECTORY_SEPARATOR . 'index.json';
    }
}
