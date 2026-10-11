<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Prism;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Prism\PrismLens;
use ExeQue\Monocle\Lenses\Packages\Prism\TracedProvider;
use Generator;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolOutput;
use RuntimeException;
use stdClass;

covers(TracedProvider::class, PrismLens::class);

/**
 * A provider that runs every offered tool once, the way a real provider
 * does when the model asks for them, and then answers.
 */
function toolRunningProvider(): Provider
{
    return new class () extends Provider {
        public function text(TextRequest $request): TextResponse
        {
            foreach ($request->tools() as $tool) {
                $tool->handle(city: 'Aarhus');
            }

            return TextResponseFake::make()->withText('done');
        }

        public function stream(TextRequest $request): Generator
        {
            foreach ($request->tools() as $tool) {
                if ($tool instanceof Tool) {
                    $tool->handle(city: 'Oslo');
                }
            }

            yield from [];
        }
    };
}

function weatherRequest(Tool ...$tools): TextRequest
{
    return new TextRequest('m', 'scripted', [], 'Weather?', [], 3, null, null, null, $tools, [], [], null);
}

describe(TracedProvider::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        $this->weather = (new Tool())->as('weather')->for('Weather')->withStringParameter('city', 'City')
            ->using(fn (string $city): string => "sunny in {$city}");
        $this->broken = (new Tool())->as('broken')->for('Breaks')->withStringParameter('city', 'City')
            ->using(fn (string $city): string => throw new RuntimeException('no forecast'));
        $this->rich = (new Tool())->as('rich')->for('Rich output')->withStringParameter('city', 'City')
            ->using(fn (string $city): ToolOutput => new ToolOutput("rich {$city}"));
    });

    it('records every tool call with its arguments, result, status and time', function (): void {
        $traced = new TracedProvider(toolRunningProvider(), app(Eyepiece::class), null);

        $traced->text(weatherRequest($this->weather, $this->broken, $this->rich));
        $traced->text(weatherRequest($this->weather));

        $capture = app(Eyepiece::class)->finalize();
        $tools = $capture->rows('prism_tools');

        expect(array_column($tools, 'tool'))->toBe(['weather', 'broken', 'rich', 'weather'])
            ->and($tools[0])->toMatchArray(['status' => 'ok', 'arguments' => ['city' => 'Aarhus'], 'result' => 'sunny in Aarhus', 'exception' => null])
            ->and($tools[0]['duration'])->toBeFloat()
            ->and($tools[1])->toMatchArray(['status' => 'failed', 'result' => null])
            ->and($tools[1]['exception']['message'])->toBe('no forecast')
            ->and($tools[2]['result'])->toBe('rich Aarhus')
            ->and($capture->spansOfKind('prism.tool'))->toHaveCount(4);

        $summary = app(PrismLens::class)->summarize($capture);

        expect($summary->totals)->toHaveKeys(['prism_tool_ms', 'prism_tool_calls'])
            ->and($summary->totals['prism_tool_calls'])->toBe(4)
            ->and($summary->errors)->toContain('Prism tool broken failed: no forecast')
            ->and(app(PrismLens::class)->presentation()->includes)->toContain('prism_tools');
    });

    it('times tools offered to a stream and runs them untimed when nothing records', function (): void {
        $traced = new TracedProvider(toolRunningProvider(), app(Eyepiece::class), null);

        iterator_to_array($traced->stream(new TextRequest('m', 'scripted', [], 'Weather?', [], 3, null, null, null, [$this->weather, new stdClass()], [], [], null)));

        expect(app(Eyepiece::class)->finalize()->rows('prism_tools')[0]['arguments'])->toBe(['city' => 'Oslo'])
            ->and($this->weather->handle(city: 'Bergen'))->toBe('sunny in Bergen');
    });

    it('describes the tool calls it records', function (): void {
        $lens = app(PrismLens::class);

        expect($lens->description())->toContain('prism_tools')
            ->and($lens->advice())->toContain('tool calls');
    });
});
