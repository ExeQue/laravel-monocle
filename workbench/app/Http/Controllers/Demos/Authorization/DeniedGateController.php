<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Authorization;

use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class DeniedGateController
{
    use RendersResult;

    public function __invoke(): View
    {
        Gate::authorize('delete-everything');

        return $this->result('Denied gate');
    }
}
