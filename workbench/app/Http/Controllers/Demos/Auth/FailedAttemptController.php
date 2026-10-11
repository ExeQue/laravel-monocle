<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class FailedAttemptController
{
    use RendersResult;

    public function __invoke(): View
    {
        $loggedIn = Auth::attempt(['email' => 'ada@example.com', 'password' => 'wrong-password']);

        return $this->result('Failed attempt', $loggedIn ? 'Logged in?' : 'Wrong password, as expected.');
    }
}
