<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Spatie;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;

/**
 * spatie/laravel-medialibrary: media added to models and the conversions
 * that ran, each conversion as a span. Conversions that run during a
 * request instead of on the queue are a common cause of slow uploads.
 */
final class MediaLibraryLens implements Lens, Summarizable
{
    private const string ADDED = 'Spatie\\MediaLibrary\\MediaCollections\\Events\\MediaHasBeenAddedEvent';

    private const string CONVERSION_STARTING = 'Spatie\\MediaLibrary\\Conversions\\Events\\ConversionWillStartEvent';

    private const string CONVERSION_COMPLETED = 'Spatie\\MediaLibrary\\Conversions\\Events\\ConversionHasBeenCompletedEvent';

    private Eyepiece $eyepiece;

    public function key(): string
    {
        return 'media';
    }

    public function description(): string
    {
        return 'spatie/laravel-medialibrary: event (added, conversion), media (Class#id), model it belongs to, collection, disk, file, size in bytes, and for conversions the conversion name, whether it is queued and its duration in ms.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        if (class_exists(self::ADDED)) {
            $eyepiece->listen(self::ADDED, function (object $event): void {
                if ($this->recording()) {
                    $this->eyepiece->push('media', ['event' => 'added', ...$this->media($event->media)]);
                }
            });
        }

        if (class_exists(self::CONVERSION_STARTING)) {
            $eyepiece->listen(self::CONVERSION_STARTING, function (object $event): void {
                if ($this->recording()) {
                    $this->eyepiece->span('conversion '.$event->conversion->getName(), 'media.conversion', $this->spanKey($event))->start([
                        ...$this->media($event->media),
                        'conversion' => $event->conversion->getName(),
                    ]);
                }
            });
        }

        if (class_exists(self::CONVERSION_COMPLETED)) {
            $eyepiece->listen(self::CONVERSION_COMPLETED, function (object $event): void {
                if (! $this->recording()) {
                    return;
                }

                $span = $this->eyepiece->span('conversion '.$event->conversion->getName(), 'media.conversion', $this->spanKey($event));
                $span->stop();

                $this->eyepiece->push('media', [
                    'event' => 'conversion',
                    ...$this->media($event->media),
                    'conversion' => $event->conversion->getName(),
                    'queued' => $event->conversion->shouldBeQueued(),
                    'duration' => $span->duration(),
                ]);
            });
        }
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('media');

        if ($rows === []) {
            return [];
        }

        $conversions = array_values(array_filter($rows, fn (array $row): bool => $row['event'] === 'conversion'));
        $ms = round(array_sum(array_column($conversions, 'duration')), 1);

        return [
            'totals' => array_filter([
                'media_added' => count($rows) - count($conversions),
                'media_conversions' => count($conversions),
                'media_conversion_ms' => $conversions === [] ? null : $ms,
            ], fn (mixed $value): bool => $value !== null && $value !== 0),
            'warnings' => $capture->type === CaptureType::Request && $conversions !== []
                ? [sprintf('%d media conversion(s) ran during the request (%s ms); queued conversions keep the response fast.', count($conversions), $ms)]
                : [],
        ];
    }

    private function recording(): bool
    {
        return $this->eyepiece->recording();
    }

    private function spanKey(object $event): string
    {
        return 'media:'.$event->media->getKey().':'.$event->conversion->getName();
    }

    /**
     * @return array<string, mixed>
     */
    private function media(object $media): array
    {
        return [
            'media' => $media::class.'#'.$media->getKey(),
            'model' => isset($media->model_type) ? $media->model_type.'#'.$media->model_id : null,
            'collection' => $media->collection_name ?? null,
            'disk' => $media->disk ?? null,
            'file' => $media->file_name ?? null,
            'size' => $media->size ?? null,
        ];
    }
}
