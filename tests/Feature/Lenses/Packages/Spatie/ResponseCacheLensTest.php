<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Spatie;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Spatie\ResponseCacheLens;
use Illuminate\Http\Request;
use Spatie\ResponseCache\Events\CacheMissedEvent;
use Spatie\ResponseCache\Events\ClearedResponseCacheEvent;
use Spatie\ResponseCache\Events\ResponseCacheHitEvent;

covers(ResponseCacheLens::class);

describe(ResponseCacheLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('warns when the response came from the cache and the controller did not run', function (): void {
        event(new ResponseCacheHitEvent(Request::create('/'), 42, ['home']));

        $capture = app(Eyepiece::class)->finalize();
        $summary = (new ResponseCacheLens())->summarize($capture);

        expect($capture->rows('response_cache')[0])->toMatchArray(['event' => 'hit', 'age_seconds' => 42, 'tags' => ['home']])
            ->and($summary['totals'])->toBe(['response_cache' => 'hit'])
            ->and($summary['warnings'][0])->toStartWith('Served from the response cache (42 s old); the controller did not run.');
    });

    it('records misses and clears without warning', function (): void {
        event(new CacheMissedEvent(Request::create('/')));
        event(new ClearedResponseCacheEvent());

        $capture = app(Eyepiece::class)->finalize();
        $summary = (new ResponseCacheLens())->summarize($capture);

        expect(array_column($capture->rows('response_cache'), 'event'))->toBe(['miss', 'cleared'])
            ->and($summary)->toBe(['totals' => ['response_cache' => 'miss'], 'warnings' => []])
            ->and((new ResponseCacheLens())->summarize(app(Eyepiece::class)->start(CaptureType::Request)))->toBe([]);
    });
});
