<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Storage;

use ExeQue\Monocle\Contracts\Store;
use ExeQue\Monocle\Data\Capture;
use Illuminate\Contracts\Cache\Repository;

/**
 * Ring buffer on top of a Laravel cache store. The array driver does not
 * share state between processes, so it cannot serve the MCP server.
 *
 * @internal
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

    public function put(Capture $capture): void
    {
        $this->cache->put($this->captureKey($capture->id), $capture->toArray(), $this->ttl);

        $this->cache->lock(self::INDEX_KEY.':lock', 2)->block(1, function () use ($capture): void {
            $index = $this->index();

            array_unshift($index, Index::summarize($capture));

            foreach (array_splice($index, max(1, $this->limit)) as $evicted) {
                $this->cache->forget($this->captureKey($evicted['id']));
            }

            $this->cache->put(self::INDEX_KEY, array_values($index), $this->ttl);
        });
    }

    public function find(string $id): ?Capture
    {
        $data = $this->cache->get($this->captureKey($id));

        return is_array($data) ? Capture::fromArray($data) : null;
    }

    public function index(): array
    {
        $index = $this->cache->get(self::INDEX_KEY);

        return is_array($index) ? array_values($index) : [];
    }

    public function clear(): void
    {
        foreach ($this->index() as $row) {
            $this->cache->forget($this->captureKey($row['id']));
        }

        $this->cache->forget(self::INDEX_KEY);
    }

    private function captureKey(string $id): string
    {
        return 'monocle:capture:'.$id;
    }
}
