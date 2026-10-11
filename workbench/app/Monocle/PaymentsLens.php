<?php

declare(strict_types=1);

namespace Workbench\App\Monocle;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Events\Dispatcher;
use Workbench\App\Events\PaymentCharged;

/**
 * An application's own lens, with its own tab in the dashboard.
 */
final class PaymentsLens implements Lens, Presentable, Summarizable
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

    /**
     * Totals for the overview: how many payments, how much was charged and
     * how many were declined; each decline is also a warning.
     */
    public function summarize(Capture $capture): Summary
    {
        $payments = $capture->rows('payments');

        $summary = Summary::make();

        if ($payments === []) {
            return $summary;
        }

        $declined = array_filter($payments, fn (array $payment): bool => $payment['status'] !== 'paid');
        $charged = array_sum(array_map(fn (array $payment): int => $payment['status'] === 'paid' ? $payment['amount'] : 0, $payments));

        $summary
            ->total('payments', count($payments))
            ->total('payments_charged', number_format($charged / 100, 2).' '.$payments[0]['currency'])
            ->total('payments_declined', count($declined));

        foreach ($declined as $payment) {
            $summary->warning("Payment of {$payment['amount']} {$payment['currency']} from {$payment['customer']} declined: {$payment['decline_reason']}.");
        }

        return $summary;
    }
}
