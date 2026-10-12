<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventSourcing;

use RuntimeException;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * A reactor that fails for one name.
 */
final class WelcomeReactor extends Reactor
{
    public function onAccountOpened(AccountOpened $event): void
    {
        if ($event->name === 'broken') {
            throw new RuntimeException('welcome mail bounced');
        }
    }
}
