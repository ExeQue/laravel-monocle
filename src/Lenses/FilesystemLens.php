<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
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
final class FilesystemLens implements Advises, Configurable, Lens, Presentable, Summarizable
{
    /** @var list<string> */
    private array $disks = [];

    public function key(): string
    {
        return 'filesystem';
    }

    public function description(): string
    {
        return 'Storage operations on the disks selected by the lens option disks (wildcards allowed): disk, driver, op (read, write, delete, fileExists, listContents, move, copy, ...), path, destination, bytes, duration, ok and the exception on failure. Native file_* calls are not covered.';
    }

    public function advice(): string
    {
        return 'Look here when file uploads, downloads or Storage calls are slow or fail.';
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

        $app->extend('filesystem', function (FilesystemManager $manager, Application $app) use ($eyepiece): FilesystemManager {
            return new MonocleFilesystemManager($app, $eyepiece, $this->records(...));
        });

        // The facade may already hold the original manager.
        Storage::clearResolvedInstance('filesystem');
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('filesystem');

        if ($rows === []) {
            return Summary::make();
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
                $reason = $row['exception']['message'] ?? 'unknown error';
                $failed[] = sprintf('Filesystem %s failed on %s:%s (%s)', $row['op'], $row['disk'], $row['path'], $reason);
            }
        }

        return Summary::make()
            ->total('filesystem_ms', round($ms, 3), 'spent', Unit::Milliseconds)
            ->totals([
                'filesystem_by_disk' => $byDisk,
            ])
            ->total('filesystem_ops', count($rows), 'operations')
            ->total('filesystem_bytes_read', $read, 'read', Unit::Bytes)
            ->total('filesystem_bytes_written', $written, 'written', Unit::Bytes)
            ->errors(array_slice($failed, 0, 3));
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.filesystem')
            ->groupBy(['path' => 'by path', 'op' => 'by operation', 'disk' => 'by disk'])
            ->format(['ok' => ['flag', 'ok', 'failed']]);
    }
}
