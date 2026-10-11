<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class DuplicateQueryController
{
    use RendersResult;

    public function __invoke(): View
    {
        foreach (range(1, 3) as $ignored) {
            $user = DB::table('users')->where('email', 'ada@example.com')->first();
        }

        return $this->result('Duplicate query', $user->name);
    }
}
