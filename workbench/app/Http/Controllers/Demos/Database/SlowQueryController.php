<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class SlowQueryController
{
    use RendersResult;

    public function __invoke(): View
    {
        $count = DB::selectOne('WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 1000000) SELECT count(*) AS total FROM n');

        return $this->result('Slow query', $count);
    }
}
