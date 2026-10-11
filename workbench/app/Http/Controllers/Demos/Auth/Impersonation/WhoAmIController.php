<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Auth\Impersonation;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Lab404\Impersonate\Services\ImpersonateManager;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class WhoAmIController
{
    use RendersResult;

    public function __invoke(ImpersonateManager $impersonate): View
    {
        return $this->result('Who am I', [
            'user' => Auth::user()?->name,
            'impersonated by' => $impersonate->isImpersonating() ? $impersonate->getImpersonatorId() : null,
        ]);
    }
}
