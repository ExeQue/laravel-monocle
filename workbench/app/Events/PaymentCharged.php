<?php

declare(strict_types=1);

namespace Workbench\App\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class PaymentCharged
{
    use Dispatchable;

    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $customer,
        public readonly string $card,
        public readonly string $status,
        public readonly ?string $declineReason = null,
    ) {
    }
}
