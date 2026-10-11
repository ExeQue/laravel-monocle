<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Prism;

use Illuminate\View\View;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class FailedCallController
{
    use RendersResult;

    public function __invoke(): View
    {
        try {
            Prism::text()->using('workbench', 'overloaded')->withPrompt('What should I pack?')->asText();
        } catch (PrismException $exception) {
            return $this->result('Failed Prism call', $exception->getMessage());
        }

        return $this->result('Failed Prism call', 'The model answered.');
    }
}
