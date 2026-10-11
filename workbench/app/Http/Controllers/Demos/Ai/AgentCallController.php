<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Ai;

use Illuminate\View\View;
use Workbench\App\Ai\Greeter;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class AgentCallController
{
    use RendersResult;

    public function __invoke(): View
    {
        Greeter::fake(['Hello Ada, lovely to meet you!']);

        return $this->result('Agent call', (string) new Greeter()->prompt('Say hi to Ada'));
    }
}
