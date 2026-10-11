<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Auth\Impersonation;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Lab404\Impersonate\Services\ImpersonateManager;
use Workbench\App\Models\User;

final class StartImpersonatingController
{
    public function __invoke(ImpersonateManager $impersonate): RedirectResponse
    {
        $ada = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $other = User::query()->whereKeyNot($ada->getKey())->firstOrFail();

        Auth::login($ada);
        $impersonate->take($ada, $other);

        return redirect()->route('demo.impersonate.whoami');
    }
}
