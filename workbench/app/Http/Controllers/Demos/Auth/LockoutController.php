<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class LockoutController
{
    use RendersResult;

    public function __invoke(Request $request): View
    {
        $key = 'login:ada@example.com|'.$request->ip();
        $attempts = 0;

        // Wrong passwords until the limiter locks the login, as a login form throttles it.
        while (! RateLimiter::tooManyAttempts($key, 3)) {
            Auth::attempt(['email' => 'ada@example.com', 'password' => 'wrong-password']);
            RateLimiter::hit($key);
            $attempts++;
        }

        event(new Lockout($request));
        RateLimiter::clear($key);

        return $this->result('Login lockout', "Locked out after {$attempts} failed attempts.");
    }
}
