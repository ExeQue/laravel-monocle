<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

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
        return 'sunny';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['city' => $schema->string()->required()];
    }
}
