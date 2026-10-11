<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Filesystem\MonocleFilesystemManager;
use ExeQue\Monocle\Lenses\Filesystem\RecordingAdapter;
use ExeQue\Monocle\Lenses\FilesystemLens;
use Illuminate\Support\Facades\Storage;

covers(FilesystemLens::class, MonocleFilesystemManager::class, RecordingAdapter::class);

describe(FilesystemLens::class, function (): void {
    beforeEach(function (): void {
        $this->root = sys_get_temp_dir().'/monocle-fs-'.bin2hex(random_bytes(4));

        config([
            'filesystems.disks.spy' => ['driver' => 'local', 'root' => $this->root.'/spy'],
            'filesystems.disks.wild-one' => ['driver' => 'local', 'root' => $this->root.'/wild'],
            'filesystems.disks.plain' => ['driver' => 'local', 'root' => $this->root.'/plain'],
        ]);

        app(Eyepiece::class)->start(CaptureType::Request);
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->root));
    });

    it('replaces the filesystem manager and records only the selected disks, wildcards included', function (): void {
        Storage::disk('spy')->put('a.txt', 'x');
        Storage::disk('wild-one')->put('b.txt', 'y');
        Storage::disk('plain')->put('c.txt', 'z');

        $disks = collect(app(Eyepiece::class)->finalize()->rows('filesystem'))->pluck('disk')->unique()->values()->all();

        expect(app('filesystem'))->toBeInstanceOf(MonocleFilesystemManager::class)
            ->and($disks)->toBe(['spy', 'wild-one'])
            ->and(app(FilesystemLens::class)->records('plain'))->toBeFalse();

        $lens = app(FilesystemLens::class);
        $lens->configure(['disks' => ['*']]);

        expect($lens->records('anything'))->toBeTrue();
    });

    it('stays out of the way when no disks are selected', function (): void {
        $lens = app(FilesystemLens::class);
        $lens->configure([]);
        $before = app('filesystem');
        $lens->boot($this->app, app(Eyepiece::class));

        expect(app('filesystem'))->toBe($before);
    });

    it('records every operation with bytes, duration and outcome', function (): void {
        $disk = Storage::disk('spy');

        $disk->put('a.txt', 'hello world');
        $disk->get('a.txt');
        $disk->exists('a.txt');
        $disk->copy('a.txt', 'b.txt');
        $disk->move('b.txt', 'c.txt');
        $disk->size('c.txt');
        $disk->files('/');
        $disk->setVisibility('c.txt', 'public');
        $disk->delete('c.txt');
        Storage::disk('plain')->put('ignored.txt', 'x');

        $disk->get('missing.txt');

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('filesystem');
        $ops = array_column($rows, 'op');

        expect($ops)->toContain('write', 'read', 'fileExists', 'copy', 'move', 'fileSize', 'listContents', 'setVisibility', 'delete')
            ->and(collect($rows)->firstWhere('op', 'write'))->toMatchArray(['disk' => 'spy', 'driver' => 'local', 'path' => 'a.txt', 'bytes' => 11, 'ok' => true])
            ->and(collect($rows)->firstWhere('op', 'read')['bytes'])->toBe(11)
            ->and(collect($rows)->firstWhere('op', 'copy')['destination'])->toBe('b.txt')
            ->and(collect($rows)->firstWhere('op', 'listContents')['items'])->toBeGreaterThanOrEqual(1)
            ->and(collect($rows)->firstWhere('op', 'setVisibility')['visibility'])->toBe('public')
            ->and(collect($rows)->pluck('disk')->unique()->all())->toBe(['spy'])
            ->and(collect($rows)->first(fn (array $row): bool => $row['ok'] === false))->toMatchArray(['op' => 'read', 'path' => 'missing.txt'])
            ->and(collect($rows)->firstWhere('op', 'write')['origin']['file'])->toContain('FilesystemLensTest.php');

        $summary = app(FilesystemLens::class)->summarize($capture);

        expect($summary['totals'])->toMatchArray(['filesystem_bytes_read' => 11, 'filesystem_bytes_written' => 11, 'filesystem_by_disk' => ['spy' => count($rows)]])
            ->and($summary['totals']['filesystem_ops'])->toBe(count($rows))
            ->and($summary['warnings'][0])->toContain('Filesystem read failed on spy:missing.txt');
    });

    it('records streams and checksums and forwards unknown calls', function (): void {
        $disk = Storage::disk('spy');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'streamed');
        rewind($stream);

        $disk->writeStream('s.txt', $stream);
        $read = $disk->readStream('s.txt');
        $checksum = $disk->checksum('s.txt');

        $rows = app(Eyepiece::class)->finalize()->rows('filesystem');

        expect(is_resource($read))->toBeTrue()
            ->and($checksum)->toBe(md5('streamed'))
            ->and(collect($rows)->firstWhere('op', 'writeStream')['bytes'])->toBe(8)
            ->and(collect($rows)->firstWhere('op', 'readStream')['bytes'])->toBe(8)
            ->and(collect($rows)->firstWhere('op', 'checksum'))->not->toBeNull();
    });

    it('summarizes nothing without operations', function (): void {
        expect(app(FilesystemLens::class)->summarize(app(Eyepiece::class)->finalize()))->toBe([]);
    });
});
