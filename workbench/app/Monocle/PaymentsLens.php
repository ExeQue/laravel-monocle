<?php

declare(strict_types=1);

namespace Workbench\App\Monocle;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Events\Dispatcher;
use Workbench\App\Events\PaymentCharged;

/**
 * An application's own lens, with its own tab in the dashboard.
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
        return 'Payments charged during the request: amount, currency, customer, card brand and last four digits, and the outcome.';
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(PaymentCharged::class, function (PaymentCharged $payment): void {
            $this->eyepiece->push('payments', [
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'customer' => $payment->customer,
                'card' => $payment->card,
                'status' => $payment->status,
                'decline_reason' => $payment->declineReason,
            ]);
        });
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->label('Payments')
            ->columns(['customer', 'amount' => 'Amount', 'currency', 'status'])
            ->detailsOnly(['card', 'decline_reason'])
            ->groupBy(['customer' => 'by customer', 'status' => 'by outcome'])
            ->details('monocle.payment');
    }
}
