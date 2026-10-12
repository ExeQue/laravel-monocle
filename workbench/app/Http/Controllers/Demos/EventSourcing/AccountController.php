<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\EventSourcing;

use Illuminate\Support\Str;
use Illuminate\View\View;
use Workbench\App\EventSourcing\AccountAggregate;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

/**
 * Opens an account and deposits into it twice through an aggregate root,
 * so the projector and the reactor run; then rebuilds it from its events.
 */
final class AccountController
{
    use RendersResult;

    public function __invoke(string $owner = 'ada'): View
    {
        $uuid = (string) Str::uuid();

        AccountAggregate::retrieve($uuid)
            ->open($owner)
            ->deposit(100, 'INV-1001')
            ->deposit(250, 'INV-1002')
            ->persist();

        $balance = AccountAggregate::retrieve($uuid)->balance;

        return $this->result('Event sourcing', ['account' => $uuid, 'owner' => $owner, 'balance' => $balance]);
    }
}
