<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Jobs;

use App\Domain\Commerce\Actions\RefundLifecycle;
use App\Domain\Commerce\Actions\SyncPayment;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Domain\Commerce\Models\Refund;
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

    public function handle(SyncPayment $sync, RefundLifecycle $refunds): void
    {
        $event = PaymentEvent::query()->find($this->paymentEventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $event->forceFill(['attempts' => $event->attempts + 1])->save();

        if ($event->kind === TransactionKind::Disbursement->value) {
            $refundId = Refund::idFromReference($event->reference);
            if ($refundId === null) {
                // Payouts (1.10) get their handler later; kept unprocessed until then.
                $event->forceFill(['error' => 'kind_not_handled'])->save();

                return;
            }

            $refund = Refund::query()->find($refundId);
            if ($refund === null) {
                $event->forceFill(['error' => 'unknown_reference'])->save();
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

                return;
            }

            $refunds->syncReference($refund, $event->reference); // the attempt this event is about
            $event->forceFill(['processed_at' => now(), 'error' => null])->save();

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
