<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Middleware;

use Closure;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Middleware\TimedMiddleware;

covers(TimedMiddleware::class);

describe(TimedMiddleware::class, function (): void {
    it('exposes the wrapped middleware with its methods and properties', function (): void {
        $inner = new class () {
            public string $mode = 'strict';

            public function handle(mixed $request, Closure $next): mixed
            {
                return $next($request);
            }

            public function except(string $path): string
            {
                return 'except '.$path;
            }
        };

        $timed = new TimedMiddleware($inner, $inner::class, 'web', app(Eyepiece::class));

        expect($timed->inner())->toBe($inner)
            ->and($timed->except('/health'))->toBe('except /health')
            ->and($timed->mode)->toBe('strict');
    });
});
