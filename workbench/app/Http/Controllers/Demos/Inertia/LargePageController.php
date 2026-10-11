<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Inertia;

use Inertia\Inertia;
use Inertia\Response;

final class LargePageController
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', array_combine(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            array_fill(0, 6, array_fill(0, 900, ['id' => 1, 'title' => 'A row in a large report'])),
        ));
    }
}
