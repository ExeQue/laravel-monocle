<?php

declare(strict_types=1);

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Lenses\CacheLens;
use ExeQue\Monocle\Lenses\DatabaseLens;
use ExeQue\Monocle\Lenses\MiddlewareLens;
use ExeQue\Monocle\Lenses\ViewsLens;
use ExeQue\Monocle\Support\Origin;

covers(DatabaseLens::class, CacheLens::class, ViewsLens::class, MiddlewareLens::class, Origin::class);

describe('warnings for library code', function (): void {
    $capture = fn (array $sections, array $spans = []): Capture => Capture::fromArray([
        'id' => 'c1', 'type' => 'request', 'started_at' => 0.0, 'ended_at' => 1.0,
        'sections' => $sections, 'spans' => $spans,
    ]);
    $library = ['file' => 'vendor/acme/lib/Thing.php', 'line' => 3, 'call' => 'Acme\\Thing->run', 'frames' => [], 'vendor' => true];
    $app = ['file' => 'app/Thing.php', 'line' => 9, 'call' => null, 'frames' => []];

    it('leaves out slow and duplicate queries from libraries but still flags N+1', function () use ($capture, $library, $app): void {
        $query = fn (int $n, array $origin, bool $slow = false, ?int $duplicateOf = null): array => [
            'n' => $n, 'sql' => 'select * from a where id = ?', 'shape' => 'select * from a where id = ?', 'type' => 'select',
            'duration' => $slow ? 200.0 : 1.0, 'slow' => $slow, 'duplicate_of' => $duplicateOf, 'origin' => $origin,
        ];

        $warnings = (new DatabaseLens())->summarize($capture(['queries' => [
            $query(1, $library, slow: true),
            $query(2, $library, duplicateOf: 1),
            $query(3, $library),
        ]]))['warnings'];

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toStartWith('Possible N+1: 3 x')
            ->and($warnings[0])->toContain('vendor/acme/lib/Thing.php:3');

        $own = (new DatabaseLens())->summarize($capture(['queries' => [$query(1, $app, slow: true), $query(2, $app, duplicateOf: 1)]]))['warnings'];

        expect(implode("\n", $own))->toContain('Slow query')->toContain('1 duplicate queries');
    });

    it('leaves out repeated cache reads from libraries', function () use ($capture, $library, $app): void {
        $reads = fn (array $origin): array => array_map(fn (int $n): array => ['n' => $n, 'type' => 'hit', 'key' => 'k', 'store' => 'array', 'duration' => 2.0, 'origin' => $origin], range(1, 6));
        $lens = new CacheLens();

        expect($lens->summarize($capture(['cache' => $reads($library)]))['warnings'])->toBe([])
            ->and($lens->summarize($capture(['cache' => $reads($app)]))['warnings'])->toHaveCount(1);
    });

    it('leaves out repeated views shipped by packages', function () use ($capture): void {
        $views = fn (string $path): array => array_map(fn (): array => ['view' => 'pager', 'path' => $path, 'duration' => 5.0], range(1, 12));
        $lens = new ViewsLens();

        expect($lens->summarize($capture(['views' => $views('vendor/acme/views/pager.blade.php')]))['warnings'])->toBe([])
            ->and($lens->summarize($capture(['views' => $views('resources/views/pager.blade.php')]))['warnings'])->toHaveCount(1);
    });

    it('leaves out slow middleware shipped by packages', function () use ($capture): void {
        $span = fn (string $id, string $class): array => ['id' => $id, 'kind' => 'middleware.request', 'name' => class_basename($class), 'start' => 0, 'end' => 80, 'meta' => ['class' => $class]];
        $lens = new MiddlewareLens();

        expect($lens->summarize($capture([], [$span('a', Illuminate\Session\Middleware\StartSession::class)]))['warnings'])->toBe([])
            ->and($lens->summarize($capture([], [$span('b', ExeQue\Monocle\Http\Middleware\Authorize::class)]))['warnings'])->toHaveCount(1);
    });
});
