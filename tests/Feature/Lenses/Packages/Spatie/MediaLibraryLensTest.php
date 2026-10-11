<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Spatie;

use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Spatie\MediaLibraryLens;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Album;

covers(MediaLibraryLens::class);

describe(MediaLibraryLens::class, function (): void {
    beforeEach(function (): void {
        $this->root = sys_get_temp_dir().'/monocle-media-'.bin2hex(random_bytes(4));
        mkdir($this->root);

        config([
            'filesystems.disks.media' => ['driver' => 'local', 'root' => $this->root],
            'media-library.disk_name' => 'media',
            'media-library.queue_conversions_by_default' => false,
        ]);

        Schema::create('albums', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
        (include dirname(__DIR__, 5).'/vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub')->up();

        $this->photo = $this->root.'/beach.png';
        $image = imagecreatetruecolor(40, 30);
        imagepng($image, $this->photo);
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->root));
    });

    it('records media added to a model and times the conversions that run during the request', function (): void {
        Route::post('/albums/{album}/photos', function (int $album) {
            Album::query()->findOrFail($album)->addMedia($this->photo)->preservingOriginal()->toMediaCollection('photos');

            return 'ok';
        });

        $album = Album::query()->create();

        $this->post('/albums/'.$album->id.'/photos')->assertOk();

        $capture = latestCapture();
        $rows = collect($capture->rows('media'));
        $added = $rows->firstWhere('event', 'added');
        $conversion = $rows->firstWhere('event', 'conversion');
        $summary = app(MediaLibraryLens::class)->summarize($capture);

        expect($added)->toMatchArray(['model' => Album::class.'#'.$album->id, 'collection' => 'photos', 'disk' => 'media', 'file' => 'beach.png'])
            ->and($added['size'])->toBeGreaterThan(0)
            ->and($conversion)->toMatchArray(['conversion' => 'thumb', 'queued' => false])
            ->and($conversion['duration'])->toBeNumeric()
            ->and($capture->spansOfKind('media.conversion')[0]->name)->toBe('conversion thumb')
            ->and($summary->totals)->toMatchArray(['media_added' => 1, 'media_conversions' => 1])
            ->and($summary->warnings[0])->toStartWith('1 media conversion(s) ran during the request');
    });

    it('ignores media and conversions handled while nothing is recording', function (): void {
        Album::query()->create()->addMedia($this->photo)->preservingOriginal()->toMediaCollection('photos');

        app(Eyepiece::class)->start(CaptureType::Request);
        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('media'))->toBe([])
            ->and(app(MediaLibraryLens::class)->summarize($capture))->toEqual(Summary::make());
    });

    it('describes its rows and shows them on the media tab', function (): void {
        $lens = app(MediaLibraryLens::class);

        expect($lens->description())->toContain('conversion name')
            ->and($lens->advice())->toStartWith('Look here when uploads are slow')
            ->and($lens->presentation()->tabView)->toBe('monocle::tabs.media');
    });
});
