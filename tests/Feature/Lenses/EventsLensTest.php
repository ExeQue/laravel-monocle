<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\EventsLens;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\OrderPlaced;
use Tests\Fixtures\OrderPlacedListener;

covers(EventsLens::class);

describe(EventsLens::class, function (): void {
    beforeEach(function (): void {
        OrderPlacedListener::$handled = 0;
        Event::listen(OrderPlaced::class, OrderPlacedListener::class);
        Event::listen('custom.string', fn (string $value) => null);
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('records application events with payload and listeners but not framework events', function (): void {
        event(new OrderPlaced(7, 'ada'));
        event('custom.string', ['hello']);
        event('unhandled.event', ['x' => 1]);
        cache()->put('k', 'v', 1);

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('events');
        $names = array_column($rows, 'event');

        expect(OrderPlacedListener::$handled)->toBe(1)
            ->and($names)->toBe([OrderPlaced::class, 'custom.string', 'unhandled.event'])
            ->and($rows[0]['payload'])->toBe(['orderId' => 7, 'customer' => 'ada'])
            ->and($rows[0]['listeners'])->toBe([OrderPlacedListener::class])
            ->and($rows[0]['broadcast'])->toBeFalse()
            ->and($rows[1]['payload'])->toBe(['hello'])
            ->and($rows[1]['listeners'])->toBe(['Closure'])
            ->and($rows[2]['listeners'])->toBe([])
            ->and($rows[0]['origin']['file'])->toContain('EventsLensTest.php');

        $summary = app(EventsLens::class)->summarize($capture);

        expect($summary['totals'])->toBe(['events' => 3, 'events_without_listeners' => 1]);
    });

    it('honours a configured ignore list', function (): void {
        $lens = app(EventsLens::class);
        $lens->configure(['ignore' => ['custom.*']]);
        $lens->boot($this->app, app(Eyepiece::class));

        event('custom.string', ['hello']);

        $names = array_column(app(Eyepiece::class)->finalize()->rows('events'), 'event');

        expect(array_count_values($names)['custom.string'] ?? 0)->toBe(1);
    });
});
