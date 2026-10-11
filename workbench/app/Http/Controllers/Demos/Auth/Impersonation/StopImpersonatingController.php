<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Auth\Impersonation;

use Illuminate\Http\RedirectResponse;
use Lab404\Impersonate\Services\ImpersonateManager;

final class StopImpersonatingController
{
    public function __invoke(ImpersonateManager $impersonate): RedirectResponse
    {
        $impersonate->leave();

        return redirect()->route('demo.impersonate.whoami');
    }
}
