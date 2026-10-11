<?php

declare(strict_types=1);

namespace Workbench\App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A slow tool, so its time stands out in the Tool calls table.
 */
final class WeatherTool implements Tool
{
    public function name(): string
    {
        return 'weather';
    }

    public function description(): Stringable|string
    {
        return 'Looks up the weather for a city.';
    }

    public function handle(Request $request): Stringable|string
    {
        usleep(80_000);

        return 'Sunny and 21 degrees in '.$request['city'].'.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['city' => $schema->string()->required()];
    }
}
