<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Session;

use Closure;
use ExeQue\Monocle\Lenses\Session\SnapshotsSession;
use Illuminate\Http\Request;

covers(SnapshotsSession::class);

describe(SnapshotsSession::class, function (): void {
    beforeEach(function (): void {
        $this->inner = new class () {
            public string $driver = 'array';

            /** @var list<string> */
            public array $terminated = [];

            public function handle(mixed $request, Closure $next): mixed
            {
                return $next($request);
            }

            public function terminate(mixed $request, mixed $response): void
            {
                $this->terminated[] = $response;
            }

            public function lifetime(): int
            {
                return 120;
            }
        };
    });

    it('carries on without a snapshot when the request has no session', function (): void {
        $snapshots = [];
        $middleware = new SnapshotsSession($this->inner, function (array $data) use (&$snapshots): void {
            $snapshots[] = $data;
        });

        $response = $middleware->handle(Request::create('/'), fn (Request $request): string => 'handled');

        expect($response)->toBe('handled')
            ->and($snapshots)->toBe([]);
    });

    it('passes terminate, unknown calls and properties to the wrapped middleware', function (): void {
        $middleware = new SnapshotsSession($this->inner, function (array $data): void {
        });

        $middleware->terminate(Request::create('/'), 'response');

        expect($middleware->inner())->toBe($this->inner)
            ->and($this->inner->terminated)->toBe(['response'])
            ->and($middleware->lifetime())->toBe(120)
            ->and($middleware->driver)->toBe('array');
    });

    it('skips terminate when the wrapped middleware has none', function (): void {
        $inner = new class () {
            public function handle(mixed $request, Closure $next): mixed
            {
                return $next($request);
            }
        };

        $middleware = new SnapshotsSession($inner, function (array $data): void {
        });

        $middleware->terminate(Request::create('/'), 'response');

        expect($middleware->inner())->toBe($inner);
    });
});
