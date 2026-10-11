<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workbench\App\Models\User;

final class LoginController
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::login(User::query()->where('email', 'ada@example.com')->firstOrFail());
        $request->session()->regenerate();

        return redirect('/');
    }
}
