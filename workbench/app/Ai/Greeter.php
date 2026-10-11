<?php

declare(strict_types=1);

namespace Workbench\App\Ai;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::OpenAI)]
#[Model('gpt-demo')]
final class Greeter implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Greet the user warmly.';
    }
}
