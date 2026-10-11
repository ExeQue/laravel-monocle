<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Pennant;

use Illuminate\View\View;
use Laravel\Pennant\Feature;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class FeatureFlagsController
{
    use RendersResult;

    public function __invoke(): View
    {
        return $this->result('Feature flags', [
            'new-dashboard' => Feature::active('new-dashboard'),
            'beta-checkout' => Feature::active('beta-checkout'),
            'undefined-feature' => Feature::active('undefined-feature'),
        ]);
    }
}
