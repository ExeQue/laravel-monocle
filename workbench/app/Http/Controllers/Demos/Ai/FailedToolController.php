<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Ai;

use Illuminate\View\View;
use Laravel\Ai\Responses\Data\ToolCall;
use RuntimeException;
use Workbench\App\Ai\TripPlanner;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class FailedToolController
{
    use RendersResult;

    public function __invoke(): View
    {
        TripPlanner::fake([
            new ToolCall('call_nok', 'exchange_rate', ['currency' => 'NOK']),
            'Pack an umbrella.',
        ]);

        try {
            new TripPlanner()->prompt('How much is 100 DKK in NOK?');
        } catch (RuntimeException $exception) {
            return $this->result('Failed tool', $exception->getMessage());
        }

        return $this->result('Failed tool', 'The tool answered.');
    }
}
