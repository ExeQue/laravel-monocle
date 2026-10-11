<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\EventsLens;
use Illuminate\Support\Facades\Event;
use Mockery;
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

        expect($summary->totals)->toBe(['events' => 3, 'events_without_listeners' => 1]);
    });

    it('masks payload keys and properties matching the input pattern', function (): void {
        event('user.login', [['email' => 'ada@example.com', 'password' => 'hunter22hunter22']]);
        event(new class ('abcdefgh12345678', 'ada') {
            public function __construct(
                public string $apiToken,
                public string $customer,
            ) {
            }
        });

        $rows = app(Eyepiece::class)->finalize()->rows('events');

        expect($rows[0]['payload'])->toBe([['email' => 'ada@example.com', 'password' => 'hunt****']])
            ->and($rows[1]['payload'])->toBe(['apiToken' => 'abcd****', 'customer' => 'ada']);
    });

    it('honours a configured ignore list', function (): void {
        $lens = app(EventsLens::class);
        $lens->configure(['ignore' => ['custom.*']]);
        $lens->boot($this->app, app(Eyepiece::class));

        event('custom.string', ['hello']);

        $names = array_column(app(Eyepiece::class)->finalize()->rows('events'), 'event');

        expect(array_count_values($names)['custom.string'] ?? 0)->toBe(1);
    });

    it('names array and invokable listeners and leaves Monocle and recording-off events out', function (): void {
        Event::listen('custom.array', [OrderPlacedListener::class, 'handle']);
        $invokable = new class () {
            public function __invoke(mixed ...$arguments): void
            {
            }
        };
        Event::listen('custom.invokable', $invokable);

        event('custom.array', [new OrderPlaced(1, 'ada')]);
        event('custom.invokable', [new OrderPlaced(2, 'ada')]);
        event('ExeQue\\Monocle\\Internal', []);

        $rows = app(Eyepiece::class)->finalize()->rows('events');

        expect(array_column($rows, 'listeners', 'event'))->toBe([
            'custom.array' => [OrderPlacedListener::class.'@handle'],
            'custom.invokable' => [$invokable::class],
        ]);

        event('custom.array', [new OrderPlaced(3, 'ada')]);

        expect(app(EventsLens::class)->summarize(app(Eyepiece::class)->start(CaptureType::Request))->totals)->toBe([])
            ->and(app(EventsLens::class)->presentation()->tabView)->toBe('monocle::tabs.events');
    });
    it('lists no listeners when the dispatcher cannot tell them', function (): void {
        $app = Mockery::mock(\Illuminate\Contracts\Foundation\Application::class);
        $app->shouldReceive('make')->with(\Illuminate\Contracts\Events\Dispatcher::class)->andReturn(Mockery::mock(\Illuminate\Contracts\Events\Dispatcher::class));
        new EventsLens(app(Eyepiece::class))->boot($app);

        event('custom.string', ['hello']);

        $rows = app(Eyepiece::class)->finalize()->rows('events');

        expect(array_column($rows, 'listeners'))->toEqualCanonicalizing([['Closure'], []]);
    });

    it('leaves Monocle listeners out and names listeners of unknown shape', function (): void {
        $dispatcher = Mockery::mock(\Illuminate\Events\Dispatcher::class);
        $dispatcher->shouldReceive('getRawListeners')->andReturn([
            'custom.raw' => ['ExeQue\\Monocle\\SomeListener', 42, 'App\\Listeners\\Audit'],
        ]);
        $app = Mockery::mock(\Illuminate\Contracts\Foundation\Application::class);
        $app->shouldReceive('make')->with(\Illuminate\Contracts\Events\Dispatcher::class)->andReturn($dispatcher);
        new EventsLens(app(Eyepiece::class))->boot($app);

        event('custom.raw', []);

        $rows = app(Eyepiece::class)->finalize()->rows('events');

        expect(array_column($rows, 'listeners'))->toEqualCanonicalizing([['listener', 'App\\Listeners\\Audit'], []]);
    });

    it('describes itself for the agent', function (): void {
        $lens = app(EventsLens::class);

        expect($lens->description())->toContain('listeners registered')
            ->and($lens->advice())->toStartWith('Look here to see which application events fired');
    });
});
