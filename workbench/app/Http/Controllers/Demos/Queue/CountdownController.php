<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Queue;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Jobs\Countdown;

final class CountdownController
{
    use RendersResult;

    public function __invoke(): View
    {
        Countdown::dispatch(3)->onConnection('database');

        return $this->result('Count down', 'Countdown(3) is on the database queue; each run queues the next until 0. Work the queue to follow the trace.');
    }
}
