<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Storage;

use Closure;
use ExeQue\Monocle\Contracts\Store;
use ExeQue\Monocle\Exceptions\InvalidStoreDriverException;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves the configured store driver: built-in file/cache, a class name
 * implementing Store, or a driver registered through Monocle::extendStore().
 *
 * @internal
 */
final class StoreManager
{
    /** @var array<string, Closure(Application, array<string, mixed>): Store> */
    private array $extensions = [];

    private ?Store $resolved = null;

    public function __construct(
        private readonly Application $app
    ) {
    }

    /**
     * @param  Closure(Application, array<string, mixed>): Store  $factory
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->extensions[$driver] = $factory;
        $this->resolved = null;
    }

    public function store(): Store
    {
        return $this->resolved ??= $this->resolve();
    }

    public function forget(): void
    {
        $this->resolved = null;
    }

    private function resolve(): Store
    {
        $config = (array) $this->app->make('config')->get('monocle.storage', []);
        $driver = (string) ($config['driver'] ?? 'file');
        $limit = (int) ($config['limit'] ?? 200);

        if (isset($this->extensions[$driver])) {
            return ($this->extensions[$driver])($this->app, $config);
        }

        if ($driver === 'file') {
            return new FileStore(
                path: (string) ($config['file']['path'] ?? $this->app->storagePath('monocle')),
                limit: $limit,
                within: $this->app->storagePath(),
            );
        }

        if ($driver === 'cache') {
            return new CacheStore(
                cache: $this->app->make('cache')->store($config['cache']['store'] ?? null),
                limit: $limit,
                ttl: (int) ($config['cache']['ttl'] ?? 86400),
            );
        }

        if (class_exists($driver) && is_subclass_of($driver, Store::class)) {
            return $this->app->make($driver);
        }

        throw InvalidStoreDriverException::forDriver($driver);
    }
}
