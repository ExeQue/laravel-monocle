<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Filesystem;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Filesystem\RecordingAdapter;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mockery;

covers(RecordingAdapter::class);

describe(RecordingAdapter::class, function (): void {
    beforeEach(function (): void {
        $this->root = sys_get_temp_dir().'/monocle-adapter-'.bin2hex(random_bytes(4));
        $this->local = new LocalFilesystemAdapter($this->root);
        $this->adapter = new RecordingAdapter($this->local, 'spy', 'local', app(Eyepiece::class));

        app(Eyepiece::class)->start(CaptureType::Request);
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->root));
    });

    it('records directory and metadata operations', function (): void {
        $this->adapter->createDirectory('dir', new Config());
        $this->adapter->write('dir/a.txt', 'hello', new Config());

        $exists = $this->adapter->directoryExists('dir');
        $visibility = $this->adapter->visibility('dir/a.txt')->visibility();
        $mimeType = $this->adapter->mimeType('dir/a.txt')->mimeType();
        $lastModified = $this->adapter->lastModified('dir/a.txt')->lastModified();

        $this->adapter->deleteDirectory('dir');

        $rows = app(Eyepiece::class)->finalize()->rows('filesystem');

        expect($exists)->toBeTrue()
            ->and($visibility)->toBe('public')
            ->and($mimeType)->toBe('text/plain')
            ->and($lastModified)->toBeInt()
            ->and(is_dir($this->root.'/dir'))->toBeFalse()
            ->and(array_column($rows, 'op'))->toBe(['createDirectory', 'write', 'directoryExists', 'visibility', 'mimeType', 'lastModified', 'deleteDirectory'])
            ->and(array_unique(array_column($rows, 'ok')))->toBe([true]);
    });

    it('does the work without recording outside a capture', function (): void {
        app(Eyepiece::class)->finalize();

        $this->adapter->write('a.txt', 'hello', new Config());

        app(Eyepiece::class)->start(CaptureType::Request);

        expect(file_get_contents($this->root.'/a.txt'))->toBe('hello')
            ->and(app(Eyepiece::class)->finalize()->rows('filesystem'))->toBe([]);
    });

    it('exposes the wrapped adapter and forwards calls it does not know', function (): void {
        $inner = Mockery::mock(FilesystemAdapter::class);
        $inner->shouldReceive('temporaryUrl')->with('a.txt')->once()->andReturn('https://example.test/a.txt');
        $adapter = new RecordingAdapter($inner, 'spy', null, app(Eyepiece::class));

        expect($adapter->inner())->toBe($inner)
            ->and($adapter->temporaryUrl('a.txt'))->toBe('https://example.test/a.txt')
            ->and(app(Eyepiece::class)->finalize()->rows('filesystem'))->toBe([]);
    });

    it('falls back to a checksum of the stream when the adapter has none of its own', function (): void {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'streamed');
        rewind($stream);

        $inner = Mockery::mock(FilesystemAdapter::class);
        $inner->shouldReceive('readStream')->with('s.txt')->once()->andReturn($stream);
        $adapter = new RecordingAdapter($inner, 'spy', null, app(Eyepiece::class));

        $checksum = $adapter->checksum('s.txt', new Config());
        $rows = app(Eyepiece::class)->finalize()->rows('filesystem');

        expect($checksum)->toBe(md5('streamed'))
            ->and(array_column($rows, 'op'))->toBe(['readStream', 'checksum'])
            ->and($rows[0]['bytes'])->toBe(8);
    });

    it('leaves the size unknown for streams that cannot report one', function (): void {
        $inner = Mockery::mock(FilesystemAdapter::class);
        // php://output cannot be stat'ed; the second answer is not a stream at all.
        $inner->shouldReceive('readStream')->andReturn(fopen('php://output', 'w'), false);
        $adapter = new RecordingAdapter($inner, 'spy', null, app(Eyepiece::class));

        $adapter->readStream('output');
        $adapter->readStream('nothing');

        expect(array_column(app(Eyepiece::class)->finalize()->rows('filesystem'), 'bytes'))->toBe([null, null]);
    });
});
