<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * A tool whose name() throws, so the lens has to fall back to its class name.
 */
final class BrokenNameTool implements Tool
{
    public function name(): string
    {
        throw new RuntimeException('no name');
    }

    public function description(): Stringable|string
    {
        return 'Has no usable name.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'done';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
