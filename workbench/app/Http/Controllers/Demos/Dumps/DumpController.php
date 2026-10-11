<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Dumps;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class DumpController
{
    use RendersResult;

    public function __invoke(): View
    {
        ob_start();
        dump(['user' => 'Ada', 'roles' => ['admin', 'editor']]);

        return $this->result('dump()', 'Dumped a value.');
    }
}
