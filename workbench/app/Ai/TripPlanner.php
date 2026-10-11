<?php

declare(strict_types=1);

namespace Workbench\App\Ai;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Workbench\App\Ai\Tools\ExchangeRateTool;
use Workbench\App\Ai\Tools\WeatherTool;

#[Provider(Lab::OpenAI)]
#[Model('gpt-demo')]
final class TripPlanner implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Plan a short trip: check the weather and the exchange rate.';
    }

    public function tools(): iterable
    {
        return [new WeatherTool(), new ExchangeRateTool()];
    }
}
