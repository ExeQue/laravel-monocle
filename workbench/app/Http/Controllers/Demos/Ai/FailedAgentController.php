<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Ai;

use Illuminate\View\View;
use RuntimeException;
use Workbench\App\Ai\Greeter;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class FailedAgentController
{
    use RendersResult;

    public function __invoke(): View
    {
        Greeter::fake(fn (): never => throw new RuntimeException('The model is overloaded.'));

        try {
            new Greeter()->prompt('Say hi to Ada');
        } catch (RuntimeException $exception) {
            return $this->result('Failed agent', $exception->getMessage());
        }

        return $this->result('Failed agent', 'The agent answered.');
    }
}
