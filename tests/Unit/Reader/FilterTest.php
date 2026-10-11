<?php

declare(strict_types=1);

namespace Tests\Unit\Reader;

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
        'counts' => ['queries' => 3],
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

    it('requires rows in every listed section', function () use ($row): void {
        expect(Filter::fromArray(['has' => ['queries']])->matches($row()))->toBeTrue()
            ->and(Filter::fromArray(['has' => ['queries', 'exceptions']])->matches($row()))->toBeFalse()
            ->and(Filter::fromArray(['has' => ['cache']])->matches($row(['counts' => ['cache' => 0]])))->toBeFalse();
    });

    it('understands relative since expressions', function () use ($row): void {
        expect(Filter::fromArray(['since' => '15m'])->matches($row()))->toBeTrue()
            ->and(Filter::fromArray(['since' => '15m'])->matches($row(['started_at' => microtime(true) - 3600])))->toBeFalse();
    });

    it('searches the name, route, type, status, label and tags', function () use ($row): void {
        $tagged = $row(['route' => 'orders.index', 'tags' => [['label' => 'impersonated by #1', 'tone' => 'warn']]]);

        expect(Filter::fromArray(['search' => 'orders.index'])->matches($tagged))->toBeTrue()
            ->and(Filter::fromArray(['search' => 'impersonated'])->matches($tagged))->toBeTrue()
            ->and(Filter::fromArray(['search' => 'nowhere'])->matches($tagged))->toBeFalse();
    });

    it('clamps offset and limit', function (): void {
        $filter = Filter::fromArray(['offset' => -5, 'limit' => 9999]);

        expect($filter->offset)->toBe(0)
            ->and($filter->limit)->toBe(500);
    });

    it('matches label, errors, trace, parent and exclusions', function () use ($row): void {
        $tagged = $row(['label' => 'before', 'has_errors' => true, 'parent_id' => 'p1', 'route' => 'orders.index']);

        expect(Filter::fromArray(['label' => 'after'])->matches($tagged))->toBeFalse()
            ->and(Filter::fromArray(['has_errors' => 'false'])->matches($tagged))->toBeFalse()
            ->and(Filter::fromArray(['trace_id' => 't2'])->matches($tagged))->toBeFalse()
            ->and(Filter::fromArray(['parent_id' => 'p2'])->matches($tagged))->toBeFalse()
            ->and(Filter::fromArray(['exclude' => ['ORDERS']])->matches($tagged))->toBeFalse()
            ->and(Filter::fromArray(['exclude' => ['orders.index']])->matches($row(['name' => 'GET /x', 'route' => 'orders.index'])))->toBeFalse()
            ->and(Filter::fromArray(['label' => 'before', 'has_errors' => true, 'trace_id' => 't1', 'parent_id' => 'p1', 'exclude' => ['/users']])->matches($tagged))->toBeTrue();
    });

    it('reads since as a timestamp, seconds, hours, days or a date', function () use ($row): void {
        $old = $row(['started_at' => microtime(true) - 7200]);

        expect(Filter::fromArray(['since' => (string) (microtime(true) - 60)])->matches($old))->toBeFalse()
            ->and(Filter::fromArray(['since' => '30s'])->matches($old))->toBeFalse()
            ->and(Filter::fromArray(['since' => '3h'])->matches($old))->toBeTrue()
            ->and(Filter::fromArray(['since' => '1d'])->matches($old))->toBeTrue()
            ->and(Filter::fromArray(['since' => '2000-01-01'])->matches($old))->toBeTrue()
            ->and(Filter::fromArray(['since' => 'not a date'])->since)->toBeNull();
    });

    it('treats empty strings as unset', function (): void {
        $filter = Filter::fromArray(['type' => '', 'since' => '']);

        expect($filter->type)->toBeNull()
            ->and($filter->since)->toBeNull();
    });
});
