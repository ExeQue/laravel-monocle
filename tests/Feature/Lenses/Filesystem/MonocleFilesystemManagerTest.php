<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Filesystem;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Filesystem\MonocleFilesystemManager;
use ExeQue\Monocle\Lenses\Filesystem\RecordingAdapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

covers(MonocleFilesystemManager::class);

describe(MonocleFilesystemManager::class, function (): void {
    beforeEach(function (): void {
        $this->root = sys_get_temp_dir().'/monocle-manager-'.bin2hex(random_bytes(4));
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->root));
    });

    it('does not wrap an adapter a custom driver already wrapped', function (): void {
        $root = $this->root;

        // Custom creators are bound to the manager, so they can hand createFlysystem() any adapter.
        Storage::extend('pre-recorded', function ($app, array $config) use ($root): FilesystemAdapter {
            $adapter = new RecordingAdapter(new LocalFilesystemAdapter($root), 'custom', 'pre-recorded', app(Eyepiece::class));

            return new FilesystemAdapter($this->createFlysystem($adapter, $config), $adapter, $config);
        });

        config(['filesystems.disks.wild-custom' => ['driver' => 'pre-recorded']]);
        app(Eyepiece::class)->start(CaptureType::Request);

        Storage::disk('wild-custom')->put('a.txt', 'x');

        $rows = app(Eyepiece::class)->finalize()->rows('filesystem');

        expect(app('filesystem'))->toBeInstanceOf(MonocleFilesystemManager::class)
            ->and(file_get_contents($root.'/a.txt'))->toBe('x')
            ->and($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray(['disk' => 'custom', 'driver' => 'pre-recorded', 'op' => 'write']);
    });
});
