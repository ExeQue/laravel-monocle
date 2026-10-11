<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Fail
{
    public function handle(mixed $request, Closure $next, string $with): mixed
    {
        throw match ($with) {
            'http' => new HttpException(409),
            'denied' => new AuthorizationException(),
            'hidden' => new AuthorizationException()->withStatus(404),
            'guest' => new AuthenticationException(),
            'invalid' => ValidationException::withMessages(['name' => 'required']),
            default => new RuntimeException('boom'),
        };
    }
}
