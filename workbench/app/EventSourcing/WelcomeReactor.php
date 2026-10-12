<?php

declare(strict_types=1);

namespace Workbench\App\EventSourcing;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Greets new account owners; mail to "bounce" fails.
 */
final class WelcomeReactor extends Reactor
{
    public function onAccountOpened(AccountOpened $event): void
    {
        if ($event->owner === 'bounce') {
            throw new RuntimeException('The welcome mail to bounce@example.com bounced.');
        }

        Log::info('Welcome mail sent to '.$event->owner);
    }
}
