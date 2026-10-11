<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Prism;

use Illuminate\View\View;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use RuntimeException;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class FailedToolController
{
    use RendersResult;

    public function __invoke(): View
    {
        $weather = new Tool()->as('weather')->for('The weather in a city')
            ->withStringParameter('city', 'The city')
            ->using(fn (string $city): never => throw new RuntimeException("No weather station in {$city}."));

        try {
            Prism::text()->using('workbench', 'demo-model')->withPrompt('Will it rain?')->withTools([$weather])->asText();
        } catch (RuntimeException $exception) {
            return $this->result('Failed Prism tool', $exception->getMessage());
        }

        return $this->result('Failed Prism tool', 'The tool answered.');
    }
}
