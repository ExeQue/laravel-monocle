<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Dumps;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Models\User;

final class DumpController
{
    use RendersResult;

    public function __invoke(): View
    {
        // The dumps are recorded; their output is kept off the page.
        ob_start();
        dump(['user' => 'Ada', 'roles' => ['admin', 'editor']]);
        dump(User::query()->firstOrFail(), now());
        ob_end_clean();

        return $this->result('dump()', 'Dumped an array, a model and a date.');
    }
}
