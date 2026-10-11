<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Dumps;

final class DieAndDumpController
{
    public function __invoke(): never
    {
        dd(['order' => 1042, 'status' => 'stuck']);
    }
}
