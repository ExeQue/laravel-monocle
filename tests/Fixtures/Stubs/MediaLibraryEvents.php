<?php

declare(strict_types=1);

/*
 * Stand-ins for spatie/laravel-medialibrary's events, so the lens can be
 * tested without the package. Same public properties as the real ones.
 */

namespace Spatie\MediaLibrary\MediaCollections\Events {
    class MediaHasBeenAddedEvent
    {
        public function __construct(public object $media)
        {
        }
    }
}

namespace Spatie\MediaLibrary\Conversions\Events {
    class ConversionWillStartEvent
    {
        public function __construct(public object $media, public object $conversion, public string $copiedOriginalFile = '')
        {
        }
    }

    class ConversionHasBeenCompletedEvent
    {
        public function __construct(public object $media, public object $conversion)
        {
        }
    }
}
