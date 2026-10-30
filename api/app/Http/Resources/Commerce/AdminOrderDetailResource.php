<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One order with everything that happened to it (PRD-09 FR-11): items and the access they granted, every payment
 * attempt with the gateway events about it, refunds, and the ledger transactions it caused. Numbers are masked.
 *
 * @mixin Order
 */
final class AdminOrderDetailResource extends JsonResource
{
    /**
     * @param  Collection<int, PaymentEvent>  $events
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  Collection<int, Refund>  $refunds
     * @param  Collection<int, LedgerTransaction>  $ledger
     */
    public function __construct(
        Order $order,
        private readonly Collection $events,
        private readonly Collection $enrollments,
        private readonly Collection $refunds,
        private readonly Collection $ledger,
    ) {
        parent::__construct($order);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $money = fn (int $minor) => Money::of($minor, Currency::from($this->currency))->toArray();

        return [
            ...(new AdminOrderResource($this->resource))->toArray($request),
            'subtotal' => $money($this->subtotal_minor),
            'discount' => $money($this->discount_minor),
            'expires_at' => $this->expires_at->toIso8601String(),
            'paid_payment_id' => $this->paid_payment_id,
            'items' => $this->itemLines(),
            'payments' => $this->paymentLines(),
            'refunds' => $this->refundLines(),
            'ledger' => $this->ledgerLines(),
        ];
    }

    /**
     * @return list<array{id: string, course_id: string, title: string, price: array{amount_minor: int, currency: string}, granted_at: string|null, enrollment_status: string|null}>
     */
    private function itemLines(): array
    {
        return $this->items->map(fn (OrderItem $i) => [
            'id' => $i->id,
            'course_id' => $i->course_id,
            'title' => $i->title_snapshot,
            'price' => $i->price()->toArray(),
            'granted_at' => $i->granted_at?->toIso8601String(),
            'enrollment_status' => $this->enrollments->firstWhere('order_item_id', $i->id)?->status->value,
        ])->values()->all();
    }

    /**
     * @return list<array{id: string, status: string, rail: string, msisdn: string, amount: array{amount_minor: int, currency: string}, confirmed_amount_minor: int|null, fee_minor: int|null, operator_reference: string|null, failure_code: string|null, failure_message: string|null, flag: string|null, checks: int, last_checked_at: string|null, created_at: string, succeeded_at: string|null, events: list<array{event_id: string, received_at: string, processed_at: string|null, error: string|null, attempts: int}>, can: array{recheck: bool}}>
     */
    private function paymentLines(): array
    {
        return $this->payments->sortBy(fn (Payment $p) => [$p->created_at, $p->id])->map(fn (Payment $p) => [
            'id' => $p->id,
            'status' => $p->status->value,
            'rail' => $p->rail->value,
            'msisdn' => $p->payer()->masked(),
            'amount' => $p->amount()->toArray(),
            'confirmed_amount_minor' => $p->confirmed_amount_minor,
            'fee_minor' => $p->fee_minor,
            'operator_reference' => $p->operator_reference,
            'failure_code' => $p->failure_code,
            'failure_message' => $p->failure_message,
            'flag' => $p->flag?->value,
            'checks' => $p->checks,
            'last_checked_at' => $p->last_checked_at?->toIso8601String(),
            'created_at' => $p->created_at->toIso8601String(),
            'succeeded_at' => $p->succeeded_at?->toIso8601String(),
            'events' => $this->events->where('reference', $p->id)->sortBy('received_at')->map(fn (PaymentEvent $e) => [
                'event_id' => $e->event_id,
                'received_at' => $e->received_at->toIso8601String(),
                'processed_at' => $e->processed_at?->toIso8601String(),
                'error' => $e->error,
                'attempts' => $e->attempts,
            ])->values()->all(),
            // A reversed payment is over; anything else may still change at the gateway.
            'can' => ['recheck' => $p->status !== PaymentStatus::Reversed],
        ])->values()->all();
    }

    /**
     * @return list<array{id: string, status: string, course: string|null, amount: array{amount_minor: int, currency: string}, created_at: string}>
     */
    private function refundLines(): array
    {
        return $this->refunds->map(fn (Refund $r) => [
            'id' => $r->id,
            'status' => $r->status->value,
            'course' => $r->item?->title_snapshot,
            'amount' => $r->amount()->toArray(),
            'created_at' => $r->created_at->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return list<array{id: string, type: string, reference_type: string, memo: string|null, created_at: string}>
     */
    private function ledgerLines(): array
    {
        return $this->ledger->map(fn (LedgerTransaction $t) => [
            'id' => $t->id,
            'type' => $t->type->value,
            'reference_type' => $t->reference_type,
            'memo' => $t->memo,
            'created_at' => $t->created_at->toIso8601String(),
        ])->values()->all();
    }
}
