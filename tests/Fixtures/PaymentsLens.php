<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * A custom lens that says how the dashboard shows its section.
 */
final class PaymentsLens implements Lens, Presentable
{
    public function __construct(
        private readonly Eyepiece $eyepiece,
    ) {
    }

    public function key(): string
    {
        return 'payments';
    }

    public function description(): string
    {
        return 'Payments charged during the request.';
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen('payments.charged', function (array $payment): void {
            $this->eyepiece->push('payments', $payment);
        });
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->label('Payments')
            ->columns(['amount' => 'Amount (DKK)', 'customer', 'status'])
            ->detailsOnly(['card'])
            ->groupBy(['customer' => 'by customer'])
            ->details('monocle-payment');
    }
}
