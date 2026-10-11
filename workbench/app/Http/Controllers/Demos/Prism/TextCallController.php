<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Prism;

use Illuminate\View\View;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class TextCallController
{
    use RendersResult;

    public function __invoke(): View
    {
        Prism::fake([
            TextResponseFake::make()->withText('Hello Ada, lovely to meet you!')->withUsage(new Usage(12, 9)),
        ]);

        return $this->result('Prism text call', Prism::text()->using('openai', 'gpt-4o')->withPrompt('Say hi to Ada')->asText()->text);
    }
}
