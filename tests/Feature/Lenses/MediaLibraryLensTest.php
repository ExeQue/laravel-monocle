<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Spatie\MediaLibraryLens;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\Conversions\Events\ConversionWillStartEvent;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

covers(MediaLibraryLens::class);

describe(MediaLibraryLens::class, function (): void {
    beforeEach(function (): void {
        $this->media = new class () {
            public string $model_type = 'App\\Models\\Album';

            public int $model_id = 3;

            public string $collection_name = 'photos';

            public string $disk = 's3';

            public string $file_name = 'beach.jpg';

            public int $size = 2048;

            public function getKey(): int
            {
                return 9;
            }
        };

        $this->conversion = new class () {
            public function getName(): string
            {
                return 'thumb';
            }

            public function shouldBeQueued(): bool
            {
                return false;
            }
        };
    });

    it('records added media and times conversions as spans', function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        event(new MediaHasBeenAddedEvent($this->media));
        event(new ConversionWillStartEvent($this->media, $this->conversion));
        usleep(2000);
        event(new ConversionHasBeenCompletedEvent($this->media, $this->conversion));

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('media');
        $span = collect($capture->spans)->first(fn ($span) => $span->kind === 'media.conversion');
        $summary = (new MediaLibraryLens())->summarize($capture);

        expect($rows[0])->toMatchArray(['event' => 'added', 'model' => 'App\\Models\\Album#3', 'collection' => 'photos', 'disk' => 's3', 'file' => 'beach.jpg', 'size' => 2048])
            ->and($rows[1])->toMatchArray(['event' => 'conversion', 'conversion' => 'thumb', 'queued' => false])
            ->and($rows[1]['duration'])->toBeGreaterThan(1)
            ->and($span->name)->toBe('conversion thumb')
            ->and($summary['totals'])->toMatchArray(['media_added' => 1, 'media_conversions' => 1])
            ->and($summary['warnings'][0])->toStartWith('1 media conversion(s) ran during the request');
    });

    it('does not warn about conversions run by a queued job', function (): void {
        app(Eyepiece::class)->start(CaptureType::Job);

        event(new ConversionWillStartEvent($this->media, $this->conversion));
        event(new ConversionHasBeenCompletedEvent($this->media, $this->conversion));

        expect((new MediaLibraryLens())->summarize(app(Eyepiece::class)->finalize())['warnings'])->toBe([]);
    });
});
