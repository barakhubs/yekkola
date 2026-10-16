<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Jobs;

use App\Domain\Commerce\Actions\SyncPayment;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Integrations\Payments\TransactionKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Handles one stored payment webhook (PRD-05 FR-07): finds our payment by reference and re-queries the
 * gateway. A webhook can beat our own start call: an unknown reference is retried with backoff, and
 * reconciliation catches anything that never resolves.
 */
final class ProcessPaymentEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300, 900];

    public function __construct(public readonly string $paymentEventId)
    {
        $this->onQueue('payments');
    }

    public function handle(SyncPayment $sync): void
    {
        $event = PaymentEvent::query()->find($this->paymentEventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $event->forceFill(['attempts' => $event->attempts + 1])->save();

        if ($event->kind !== TransactionKind::Collection->value) {
            // Disbursements (refunds, payouts) are handled with 1.7c / 1.10.
            $event->forceFill(['processed_at' => now(), 'error' => 'kind_not_handled'])->save();

            return;
        }

        $payment = Payment::query()->find($event->reference);
        if ($payment === null) {
            $event->forceFill(['error' => 'unknown_reference'])->save();
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        $sync->handle($payment);
        $event->forceFill(['processed_at' => now(), 'error' => null])->save();
    }

    public function failed(Throwable $e): void
    {
        PaymentEvent::query()->whereKey($this->paymentEventId)->update(['error' => mb_substr($e->getMessage(), 0, 255)]);
    }
}
