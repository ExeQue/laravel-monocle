<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Filesystem;

use Closure;
use ExeQue\Monocle\Eyepiece;
use League\Flysystem\CalculateChecksumFromStream;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use Throwable;

/**
 * Decorates a Flysystem adapter so every operation on the disk becomes a row
 * in the filesystem section: operation, path, bytes, duration and outcome.
 */
final class RecordingAdapter implements ChecksumProvider, FilesystemAdapter
{
    use CalculateChecksumFromStream;

    public function __construct(
        private readonly FilesystemAdapter $inner,
        private readonly string $disk,
        private readonly ?string $driver,
        private readonly Eyepiece $eyepiece,
    ) {
    }

    public function fileExists(string $path): bool
    {
        return $this->record('fileExists', $path, fn (): bool => $this->inner->fileExists($path));
    }

    public function directoryExists(string $path): bool
    {
        return $this->record('directoryExists', $path, fn (): bool => $this->inner->directoryExists($path));
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->record('write', $path, function () use ($path, $contents, $config): void {
            $this->inner->write($path, $contents, $config);
        }, ['bytes' => strlen($contents)]);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->record('writeStream', $path, function () use ($path, $contents, $config): void {
            $this->inner->writeStream($path, $contents, $config);
        }, ['bytes' => $this->streamSize($contents)]);
    }

    public function read(string $path): string
    {
        return $this->record('read', $path, fn (): string => $this->inner->read($path), [], fn (string $contents): array => ['bytes' => strlen($contents)]);
    }

    public function readStream(string $path)
    {
        return $this->record('readStream', $path, fn () => $this->inner->readStream($path), [], fn ($stream): array => ['bytes' => $this->streamSize($stream)]);
    }

    public function delete(string $path): void
    {
        $this->record('delete', $path, function () use ($path): void {
            $this->inner->delete($path);
        });
    }

    public function deleteDirectory(string $path): void
    {
        $this->record('deleteDirectory', $path, function () use ($path): void {
            $this->inner->deleteDirectory($path);
        });
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->record('createDirectory', $path, function () use ($path, $config): void {
            $this->inner->createDirectory($path, $config);
        });
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->record('setVisibility', $path, function () use ($path, $visibility): void {
            $this->inner->setVisibility($path, $visibility);
        }, ['visibility' => $visibility]);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->record('visibility', $path, fn (): FileAttributes => $this->inner->visibility($path));
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->record('mimeType', $path, fn (): FileAttributes => $this->inner->mimeType($path));
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->record('lastModified', $path, fn (): FileAttributes => $this->inner->lastModified($path));
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->record('fileSize', $path, fn (): FileAttributes => $this->inner->fileSize($path), [], fn (FileAttributes $attributes): array => ['bytes' => $attributes->fileSize()]);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->record('listContents', $path, fn (): array => iterator_to_array($this->inner->listContents($path, $deep), false), ['deep' => $deep], fn (array $items): array => ['items' => count($items)]);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->record('move', $source, function () use ($source, $destination, $config): void {
            $this->inner->move($source, $destination, $config);
        }, ['destination' => $destination]);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->record('copy', $source, function () use ($source, $destination, $config): void {
            $this->inner->copy($source, $destination, $config);
        }, ['destination' => $destination]);
    }

    public function checksum(string $path, Config $config): string
    {
        return $this->record('checksum', $path, fn (): string => $this->inner instanceof ChecksumProvider
            ? $this->inner->checksum($path, $config)
            : $this->calculateChecksumFromStream($path, $config));
    }

    public function inner(): FilesystemAdapter
    {
        return $this->inner;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @param  array<string, mixed>  $meta
     * @param  Closure(T): array<string, mixed>|null  $afterwards
     * @return T
     */
    private function record(string $operation, string $path, Closure $operation_, array $meta = [], ?Closure $afterwards = null): mixed
    {
        if (! $this->eyepiece->recording()) {
            return $operation_();
        }

        $started = $this->eyepiece->now();
        $row = ['disk' => $this->disk, 'driver' => $this->driver, 'op' => $operation, 'path' => $path, 'destination' => $meta['destination'] ?? null, 'bytes' => $meta['bytes'] ?? null, ...$meta];

        try {
            $result = $operation_();

            if ($afterwards !== null) {
                $row = [...$row, ...$afterwards($result)];
            }

            $row['ok'] = true;
            $row['exception'] = null;

            return $result;
        } catch (Throwable $exception) {
            $row['ok'] = false;
            $row['exception'] = ['class' => $exception::class, 'message' => $exception->getMessage()];

            throw $exception;
        } finally {
            $row['duration'] = round($this->eyepiece->now() - $started, 3);
            $this->eyepiece->push('filesystem', $row);
        }
    }

    private function streamSize(mixed $stream): ?int
    {
        if (! is_resource($stream)) {
            return null;
        }

        $stats = fstat($stream);

        return is_array($stats) && isset($stats['size']) ? (int) $stats['size'] : null;
    }
}
