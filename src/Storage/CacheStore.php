<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Storage;

use ExeQue\Monocle\Contracts\Store;
use ExeQue\Monocle\Data\Entry;
use Illuminate\Contracts\Cache\Repository;

/**
 * Ring buffer on top of a Laravel cache store. The array driver does not
 * share state between processes, so it cannot serve the MCP server.
 */
final class CacheStore implements Store
{
    private const string INDEX_KEY = 'monocle:index';

    public function __construct(
        private readonly Repository $cache,
        private readonly int $limit,
        private readonly int $ttl,
    ) {
    }

    public function put(Entry $entry): void
    {
        $this->cache->put($this->entryKey($entry->id), $entry->toArray(), $this->ttl);

        $this->cache->lock(self::INDEX_KEY . ':lock', 2)->block(1, function () use ($entry): void {
            $index = $this->index();

            array_unshift($index, Index::summarize($entry));

            foreach (array_splice($index, max(1, $this->limit)) as $evicted) {
                $this->cache->forget($this->entryKey($evicted['id']));
            }

            $this->cache->put(self::INDEX_KEY, array_values($index), $this->ttl);
        });
    }

    public function find(string $id): ?Entry
    {
        $data = $this->cache->get($this->entryKey($id));

        return is_array($data) ? Entry::fromArray($data) : null;
    }

    public function index(): array
    {
        $index = $this->cache->get(self::INDEX_KEY);

        return is_array($index) ? array_values($index) : [];
    }

    public function clear(): void
    {
        foreach ($this->index() as $row) {
            $this->cache->forget($this->entryKey($row['id']));
        }

        $this->cache->forget(self::INDEX_KEY);
    }

    private function entryKey(string $id): string
    {
        return 'monocle:entry:' . $id;
    }
}
