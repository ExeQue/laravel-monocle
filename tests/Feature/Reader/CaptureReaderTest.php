<?php

declare(strict_types=1);

namespace Tests\Feature\Reader;

use ExeQue\Monocle\Contracts\Store;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use ExeQue\Monocle\Storage\StoreManager;
use Mockery;

covers(CaptureReader::class);

describe(CaptureReader::class, function (): void {
    it('finds no latest capture in an empty store', function (): void {
        expect(app(CaptureReader::class)->find('latest'))->toBeNull()
            ->and(app(CaptureReader::class)->find(''))->toBeNull();
    });

    it('clears the store', function (): void {
        $capture = new Capture('c1', CaptureType::Request, microtime(true));
        $capture->endedAt = microtime(true);
        app(StoreManager::class)->store()->put($capture);

        $reader = app(CaptureReader::class);
        $reader->clear();

        expect($reader->find('c1'))->toBeNull();
    });

    it('lists a capture without a start time from a custom store', function (): void {
        $store = Mockery::mock(Store::class);
        $store->allows('index')->andReturn([['id' => 'c1', 'type' => 'request']]);
        config()->set('monocle.storage.driver', 'startless');
        app(StoreManager::class)->extend('startless', fn (): Store => $store);

        expect(app(CaptureReader::class)->list(Filter::fromArray([]))['rows'])->toBe([['id' => 'c1', 'type' => 'request', 'started_at' => null]]);
    });
});
