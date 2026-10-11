<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Validation;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Http\Requests\ContactRequest;

final class FormRequestController
{
    use RendersResult;

    public function __invoke(ContactRequest $request): View
    {
        return $this->result('Validation passed', $request->validated());
    }
}
