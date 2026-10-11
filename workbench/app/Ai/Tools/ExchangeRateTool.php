<?php

declare(strict_types=1);

namespace Workbench\App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * Fails for currencies it does not know, so a failed tool call shows up too.
 */
final class ExchangeRateTool implements Tool
{
    public function name(): string
    {
        return 'exchange_rate';
    }

    public function description(): Stringable|string
    {
        return 'The exchange rate from DKK to another currency.';
    }

    public function handle(Request $request): Stringable|string
    {
        usleep(15_000);

        return match ($request['currency']) {
            'EUR' => '1 DKK = 0.134 EUR',
            'USD' => '1 DKK = 0.146 USD',
            default => throw new RuntimeException('No rate for '.$request['currency'].'.'),
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return ['currency' => $schema->string()->required()];
    }
}
