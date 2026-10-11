<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Closure;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Storage\FileStore;
use RuntimeException;

covers(FileStore::class);

describe(FileStore::class, function (): void {
    $directory = fn (): string => sys_get_temp_dir().'/monocle-filestore-'.bin2hex(random_bytes(4));

    $capture = function (string $id): Capture {
        $capture = new Capture($id, CaptureType::Request, microtime(true));
        $capture->endedAt = microtime(true);

        return $capture;
    };

    /**
     * Runs the callback with PHP warnings silenced, the way a production error
     * handler that does not throw would see them.
     */
    $quietly = function (Closure $callback): mixed {
        set_error_handler(fn (): bool => true);

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    };

    it('may live directly in the directory it must stay inside', function () use ($directory): void {
        $path = $directory();
        $store = new FileStore($path, 3, $path.'/');

        expect($store->path())->toBe($path)
            ->and(FileStore::isInside('/var/app/storage', '/var/app/storage/'))->toBeTrue();
    });

    it('clears nothing when the directory does not exist yet', function () use ($directory): void {
        $path = $directory();

        (new FileStore($path, 3, sys_get_temp_dir()))->clear();

        expect(is_dir($path))->toBeFalse();
    });

    it('finds nothing when a capture file cannot be read', function () use ($directory, $capture): void {
        $path = $directory();
        $store = new FileStore($path, 3, sys_get_temp_dir());
        $store->put($capture('unreadable'));
        chmod($path.'/unreadable'.FileStore::SUFFIX, 0000);

        try {
            expect($store->find('unreadable'))->toBeNull();
        } finally {
            chmod($path.'/unreadable'.FileStore::SUFFIX, 0644);
        }
    });

    it('fails loudly when the storage directory cannot be created', function () use ($directory, $capture): void {
        $blocker = $directory();
        file_put_contents($blocker, 'a file where a directory should be');

        try {
            expect(fn () => (new FileStore($blocker.'/store', 3, sys_get_temp_dir()))->put($capture('c1')))
                ->toThrow(RuntimeException::class, 'could not create the storage directory');
        } finally {
            unlink($blocker);
        }
    });

    it('fails loudly when a capture cannot be written', function () use ($directory, $capture, $quietly): void {
        $path = $directory();
        $store = new FileStore($path, 3, sys_get_temp_dir());
        $store->put($capture('first'));
        chmod($path, 0555);

        try {
            expect(fn () => $quietly(fn () => $store->put($capture('second'))))
                ->toThrow(RuntimeException::class, 'could not write');
        } finally {
            chmod($path, 0775);
        }

        expect(glob($path.'/*.tmp'))->toBe([]);
    });

    it('fails loudly when the index lock cannot be opened', function () use ($directory, $capture, $quietly): void {
        $path = $directory();
        mkdir($path.'/index.monocle.lock', 0775, true);
        $store = new FileStore($path, 3, sys_get_temp_dir());

        expect(fn () => $quietly(fn () => $store->put($capture('c1'))))
            ->toThrow(RuntimeException::class, 'could not open the index lock');
    });
});
