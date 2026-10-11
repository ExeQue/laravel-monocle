<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Inertia;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Inertia\InertiaLens;
use ExeQue\Monocle\LensRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;

covers(InertiaLens::class);

describe(InertiaLens::class, function (): void {
    beforeEach(function (): void {
        View::addLocation(dirname(__DIR__, 4).'/Fixtures/views');
        Inertia::setRootView('inertia-app');
        Inertia::version('v1');
        Inertia::share('app', ['name' => 'Shop']);
        Inertia::share('flash', Inertia::always(['ok' => true]));

        Route::get('/orders', fn () => Inertia::render('Orders/Index', [
            'orders' => [['id' => 1], ['id' => 2]],
            'customer' => fn (): array => ['name' => 'Ada'],
            'stats' => Inertia::defer(fn (): array => ['revenue' => 10], 'metrics'),
            'filters' => Inertia::optional(fn (): array => ['status' => 'open']),
            'feed' => Inertia::merge(['a', 'b']),
            'big' => str_repeat('x', 200),
        ]));
    });

    $lens = fn (): InertiaLens => collect(app(LensRegistry::class)->booted())->first(fn (object $lens): bool => $lens instanceof InertiaLens);
    $props = fn (): array => array_column(latestCapture()->rows('inertia'), null, 'prop');

    it('records every prop of a first load with its type, size and why it was left out', function () use ($props): void {
        $this->get('/orders')->assertOk();

        $rows = $props();
        $page = latestCapture()->rows('inertia_pages')[0];

        expect(array_keys($rows))->toBe(['app', 'flash', 'orders', 'customer', 'stats', 'filters', 'feed', 'big'])
            ->and($rows['app'])->toMatchArray(['type' => 'plain', 'shared' => true, 'sent' => true, 'size_bytes' => strlen('{"name":"Shop"}')])
            ->and($rows['flash'])->toMatchArray(['type' => 'always', 'shared' => true])
            ->and($rows['orders'])->toMatchArray(['type' => 'plain', 'shared' => false, 'sent' => true, 'size_bytes' => strlen('[{"id":1},{"id":2}]')])
            ->and($rows['customer'])->toMatchArray(['type' => 'closure', 'sent' => true])
            ->and($rows['stats'])->toMatchArray(['type' => 'defer', 'sent' => false, 'size_bytes' => null, 'group' => 'metrics', 'omitted' => 'deferred'])
            ->and($rows['filters'])->toMatchArray(['type' => 'optional', 'sent' => false, 'omitted' => 'optional'])
            ->and($rows['feed'])->toMatchArray(['type' => 'merge', 'merge' => 'append', 'sent' => true])
            ->and($rows['big']['size_bytes'])->toBe(202)
            ->and($page)->toMatchArray([
                'component' => 'Orders/Index',
                'url' => '/orders',
                'version' => 'v1',
                'visit' => 'first load',
                'partial' => null,
                'props_sent' => 6,
                'props_omitted' => 2,
                'deferred' => ['metrics' => ['stats']],
            ])
            ->and($page['payload_bytes'])->toBeGreaterThan(300);
    });

    it('records an Inertia visit as one', function (): void {
        $this->get('/orders', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'v1'])->assertOk();

        $page = latestCapture()->rows('inertia_pages')[0];

        expect($page['visit'])->toBe('inertia')
            ->and($page['payload_bytes'])->toBeGreaterThan(300);
    });

    it('records what a partial reload asked for and what it left out', function () use ($props): void {
        $this->get('/orders', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'v1',
            'X-Inertia-Partial-Component' => 'Orders/Index',
            'X-Inertia-Partial-Data' => 'stats,filters',
        ])->assertOk();

        $rows = $props();
        $page = latestCapture()->rows('inertia_pages')[0];

        expect($rows['stats'])->toMatchArray(['sent' => true, 'omitted' => null])
            ->and($rows['filters']['sent'])->toBeTrue()
            ->and($rows['orders'])->toMatchArray(['sent' => false, 'omitted' => 'partial reload'])
            ->and($rows['app'])->toMatchArray(['sent' => false, 'omitted' => 'partial reload'])
            ->and($rows['flash'])->toMatchArray(['sent' => true, 'shared_on_partial' => null])
            ->and($page['partial'])->toBe(['component' => 'Orders/Index', 'only' => ['stats', 'filters']]);
    });

    it('warns about shared props a partial reload with an except list gets anyway', function () use ($props, $lens): void {
        $this->get('/orders', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'v1',
            'X-Inertia-Partial-Component' => 'Orders/Index',
            'X-Inertia-Partial-Except' => 'orders',
        ])->assertOk();

        $rows = $props();
        $summary = $lens()->summarize(latestCapture());

        expect($rows['orders']['sent'])->toBeFalse()
            ->and($rows['app']['shared_on_partial'])->toBeTrue()
            ->and($rows['flash']['shared_on_partial'])->toBeNull()
            ->and(latestCapture()->rows('inertia_pages')[0]['partial'])->toBe(['component' => 'Orders/Index', 'except' => ['orders']])
            ->and(implode("\n", $summary->warnings))->toContain('Shared Inertia prop app was sent on a partial reload');
    });

    it('sums the page up and warns about large props and pages', function () use ($lens): void {
        $lens = $lens();
        $lens->configure(['large_prop_bytes' => 100, 'large_page_bytes' => 200]);

        $this->get('/orders')->assertOk();

        $capture = latestCapture();
        $rows = array_column($capture->rows('inertia'), null, 'prop');
        $summary = $lens->summarize($capture);

        expect($rows['big']['large'])->toBeTrue()
            ->and($rows['orders']['large'])->toBeNull()
            ->and($summary->totals['inertia_props'])->toBe(6)
            ->and($summary->totals['inertia_payload_bytes'])->toBe($capture->rows('inertia_pages')[0]['payload_bytes'])
            ->and($summary->totals['inertia_deferred'])->toBe(1)
            ->and($summary->warnings)->toContain('Inertia prop big is 202 bytes, over 100.')
            ->and(implode("\n", $summary->warnings))->toContain('Inertia page Orders/Index is');
    });

    it('records nothing for responses that are not Inertia pages', function () use ($lens): void {
        Route::get('/plain', fn () => 'hello');

        $this->get('/plain')->assertOk();

        expect(latestCapture()->rows('inertia'))->toBe([])
            ->and(latestCapture()->rows('inertia_pages'))->toBe([])
            ->and($lens()->summarize(latestCapture())->totals)->toBe([]);
    });

    it('records nothing outside a capture', function (): void {
        app('router')->prepareResponse(request(), Inertia::render('Orders/Index', ['a' => 1]));

        app(Eyepiece::class)->start(CaptureType::Request);
        $capture = app(Eyepiece::class)->finalize();

        expect($capture->rows('inertia'))->toBe([])
            ->and($capture->rows('inertia_pages'))->toBe([]);
    });
});
