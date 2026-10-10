<?php

declare(strict_types=1);

use ExeQue\Monocle\Reader\Filter;

covers(Filter::class);

describe(Filter::class, function (): void {
    $row = fn (array $overrides = []): array => array_merge([
        'type' => 'request',
        'name' => 'GET /orders',
        'status' => '200',
        'duration' => 120.5,
        'started_at' => microtime(true),
        'label' => null,
        'has_errors' => false,
        'trace_id' => 't1',
        'parent_id' => null,
    ], $overrides);

    it('matches on type, uri substring and status', function () use ($row): void {
        expect(Filter::fromArray(['type' => 'request', 'uri' => 'orders', 'status' => 200])->matches($row()))->toBeTrue()
            ->and(Filter::fromArray(['type' => 'job'])->matches($row()))->toBeFalse()
            ->and(Filter::fromArray(['uri' => 'users'])->matches($row()))->toBeFalse()
            ->and(Filter::fromArray(['status' => 500])->matches($row()))->toBeFalse();
    });

    it('matches on minimum duration, label and errors', function () use ($row): void {
        expect(Filter::fromArray(['min_duration_ms' => 100])->matches($row()))->toBeTrue()
            ->and(Filter::fromArray(['min_duration_ms' => 200])->matches($row()))->toBeFalse()
            ->and(Filter::fromArray(['label' => 'baseline'])->matches($row(['label' => 'baseline'])))->toBeTrue()
            ->and(Filter::fromArray(['has_errors' => true])->matches($row()))->toBeFalse();
    });

    it('understands relative since expressions', function () use ($row): void {
        expect(Filter::fromArray(['since' => '15m'])->matches($row()))->toBeTrue()
            ->and(Filter::fromArray(['since' => '15m'])->matches($row(['started_at' => microtime(true) - 3600])))->toBeFalse();
    });

    it('clamps offset and limit', function (): void {
        $filter = Filter::fromArray(['offset' => -5, 'limit' => 9999]);

        expect($filter->offset)->toBe(0)
            ->and($filter->limit)->toBe(500);
    });
});
