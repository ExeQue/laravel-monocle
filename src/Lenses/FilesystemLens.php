<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Filesystem\MonocleFilesystemManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Filesystem operations on the disks named in the lens option `disks`
 * (wildcards allowed). Laravel fires no events for Storage, so the filesystem
 * manager is replaced with one that wraps those disks' adapters.
 */
final class FilesystemLens implements Configurable, Lens, Summarizable
{
    /** @var list<string> */
    private array $disks = [];

    public function key(): string
    {
        return 'fs';
    }

    public function description(): string
    {
        return 'Storage operations on the disks selected by the lens option disks (wildcards allowed): disk, driver, op (read, write, delete, fileExists, listContents, move, copy, ...), path, destination, bytes, duration, ok and the exception on failure. Native file_* calls are not covered.';
    }

    public function configure(array $options): void
    {
        $this->disks = array_values(array_map('strval', (array) ($options['disks'] ?? [])));
    }

    public function records(string $disk): bool
    {
        foreach ($this->disks as $pattern) {
            if (Str::is($pattern, $disk)) {
                return true;
            }
        }

        return false;
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        if ($this->disks === []) {
            return;
        }

        $app->extend('filesystem', fn (FilesystemManager $manager, Application $app): FilesystemManager => new MonocleFilesystemManager($app, $eyepiece, $this->records(...)));

        Storage::clearResolvedInstance('filesystem');
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('fs');

        if ($rows === []) {
            return [];
        }

        $read = 0;
        $written = 0;
        $ms = 0.0;
        $byDisk = [];
        $failed = [];

        foreach ($rows as $row) {
            $ms += (float) ($row['duration'] ?? 0);
            $byDisk[$row['disk']] = ($byDisk[$row['disk']] ?? 0) + 1;

            if (in_array($row['op'], ['read', 'readStream'], true)) {
                $read += (int) ($row['bytes'] ?? 0);
            }

            if (in_array($row['op'], ['write', 'writeStream'], true)) {
                $written += (int) ($row['bytes'] ?? 0);
            }

            if (($row['ok'] ?? true) === false) {
                $failed[] = sprintf('Filesystem %s failed on %s:%s (%s)', $row['op'], $row['disk'], $row['path'], $row['exception']['message'] ?? 'unknown error');
            }
        }

        return [
            'totals' => [
                'fs_ops' => count($rows),
                'fs_ms' => round($ms, 3),
                'fs_bytes_read' => $read,
                'fs_bytes_written' => $written,
                'fs_by_disk' => $byDisk,
            ],
            'warnings' => array_slice($failed, 0, 3),
        ];
    }
}
