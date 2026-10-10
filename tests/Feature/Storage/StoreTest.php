<?php

declare(strict_types=1);

use ExeQue\Monocle\Contracts\Store;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Exceptions\InvalidStoreDriverException;
use ExeQue\Monocle\Facades\Monocle;
use ExeQue\Monocle\Storage\CacheStore;
use ExeQue\Monocle\Storage\FileStore;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Support\Facades\Cache;

covers(FileStore::class, CacheStore::class, StoreManager::class, InvalidStoreDriverException::class);

function makeCapture(string $id, ?string $parent = null, ?string $trace = null): Capture
{
    $capture = new Capture($id, CaptureType::Request, microtime(true) - 1, $parent, $trace);
    $capture->name = "GET /{$id}";
    $capture->status = '200';
    $capture->endedAt = microtime(true);
    $capture->sections['queries'] = [['sql' => 'select 1', 'duration' => 1.0]];

    return $capture;
}

describe('stores', function (): void {
    $drivers = [
        'file' => fn (): Store => new FileStore(sys_get_temp_dir().'/monocle-store-'.bin2hex(random_bytes(4)), 3),
        'cache' => fn (): Store => new CacheStore(Cache::store('array'), 3, 60),
    ];

    foreach ($drivers as $driver => $factory) {
        describe($driver, function () use ($factory): void {
            it('acts as a ring buffer and evicts the oldest captures', function () use ($factory): void {
                $store = $factory();

                foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
                    $store->put(makeCapture($id));
                }

                expect(array_column($store->index(), 'id'))->toBe(['e', 'd', 'c'])
                    ->and($store->find('a'))->toBeNull()
                    ->and($store->find('e'))->toBeInstanceOf(Capture::class)
                    ->and($store->find('e')->sections['queries'])->toHaveCount(1);
            });

            it('keeps summary data in the index', function () use ($factory): void {
                $store = $factory();
                $store->put(makeCapture('x', 'parent-1', 'trace-1'));

                $row = $store->index()[0];

                expect($row)->toMatchArray(['id' => 'x', 'parent_id' => 'parent-1', 'trace_id' => 'trace-1', 'type' => 'request', 'name' => 'GET /x', 'status' => '200'])
                    ->and($row['counts'])->toBe(['queries' => 1]);
            });

            it('clears everything', function () use ($factory): void {
                $store = $factory();
                $store->put(makeCapture('x'));
                $store->clear();

                expect($store->index())->toBe([])
                    ->and($store->find('x'))->toBeNull();
            });
        });
    }

    it('writes a .gitignore into a fresh file store directory', function (): void {
        $path = sys_get_temp_dir().'/monocle-store-'.bin2hex(random_bytes(4));
        (new FileStore($path, 3))->put(makeCapture('a'));

        expect(file_get_contents($path.'/.gitignore'))->toContain('*');

        exec('rm -rf '.escapeshellarg($path));
    });
});

describe(StoreManager::class, function (): void {
    it('resolves the configured driver', function (): void {
        config(['monocle.storage.driver' => 'cache']);
        $manager = new StoreManager($this->app);

        expect($manager->store())->toBeInstanceOf(CacheStore::class);

        config(['monocle.storage.driver' => 'file']);
        $manager->forget();

        expect($manager->store())->toBeInstanceOf(FileStore::class);
    });

    it('accepts a class name implementing the contract', function (): void {
        config(['monocle.storage.driver' => CacheStore::class]);
        $this->app->bind(CacheStore::class, fn (): CacheStore => new CacheStore(Cache::store('array'), 5, 60));

        expect((new StoreManager($this->app))->store())->toBeInstanceOf(CacheStore::class);
    });

    it('accepts drivers registered through the facade', function (): void {
        config(['monocle.storage.driver' => 'custom']);

        Monocle::extendStore('custom', fn ($app, array $config): Store => new CacheStore(Cache::store('array'), (int) $config['limit'], 60));

        expect(app(StoreManager::class)->store())->toBeInstanceOf(CacheStore::class);
    });

    it('rejects unknown drivers', function (): void {
        config(['monocle.storage.driver' => 'nope']);

        expect(fn () => (new StoreManager($this->app))->store())->toThrow(InvalidStoreDriverException::class);
    });
});
