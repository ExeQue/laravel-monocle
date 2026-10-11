<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Validation;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class InlineValidationController
{
    use RendersResult;

    public function __invoke(Request $request): View
    {
        return $this->result('Inline validation passed', $request->validate([
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]));
    }
}
