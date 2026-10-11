<?php

declare(strict_types=1);

namespace Workbench\App\Ai;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * A sub-agent: TripPlanner offers it as a tool.
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-demo-mini')]
final class PackingAdvisor implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Say what to pack for a trip.';
    }
}
