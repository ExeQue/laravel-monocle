<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Spatie;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\Conversions\Events\ConversionWillStartEvent;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

/**
 * spatie/laravel-medialibrary: media added to models and the conversions
 * that ran, each conversion as a span. Conversions that run during a
 * request instead of on the queue are a common cause of slow uploads.
 */
final class MediaLibraryLens implements Advises, Lens, Summarizable
{
    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'media';
    }

    public function description(): string
    {
        return 'spatie/laravel-medialibrary: event (added, conversion), media (Class#id), model it belongs to, collection, disk, file, size in bytes, and for conversions the conversion name, whether it is queued and its duration in ms.';
    }

    public function advice(): string
    {
        return 'Look here when uploads are slow: media added and image conversions that ran during the request instead of on the queue.';
    }

    public function boot(): void
    {
        if (class_exists(MediaHasBeenAddedEvent::class)) {
            $this->eyepiece->listen(MediaHasBeenAddedEvent::class, function (object $event): void {
                if ($this->recording()) {
                    $this->eyepiece->push('media', ['event' => 'added', ...$this->media($event->media)]);
                }
            });
        }

        if (class_exists(ConversionWillStartEvent::class)) {
            $this->eyepiece->listen(ConversionWillStartEvent::class, function (object $event): void {
                if ($this->recording()) {
                    $this->eyepiece->span('conversion '.$event->conversion->getName(), 'media.conversion', $this->spanKey($event))->start([
                        ...$this->media($event->media),
                        'conversion' => $event->conversion->getName(),
                    ]);
                }
            });
        }

        if (class_exists(ConversionHasBeenCompletedEvent::class)) {
            $this->eyepiece->listen(ConversionHasBeenCompletedEvent::class, function (object $event): void {
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
