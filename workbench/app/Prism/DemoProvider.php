<?php

declare(strict_types=1);

namespace Workbench\App\Prism;

use Prism\Prism\Providers\Provider;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Usage;

/**
 * A Prism provider for the demo: it runs every tool offered with the
 * request once, as a real provider does when the model asks for them,
 * and answers without calling out.
 */
final class DemoProvider extends Provider
{
    public function text(TextRequest $request): TextResponse
    {
        $answers = [];

        foreach ($request->tools() as $tool) {
            if (! $tool instanceof Tool) {
                continue;
            }

            $answers[] = $tool->handle(city: 'Copenhagen');
        }

        return TextResponseFake::make()
            ->withText('Packed for: '.implode(' ', $answers))
            ->withUsage(new Usage(42, 12));
    }
}
