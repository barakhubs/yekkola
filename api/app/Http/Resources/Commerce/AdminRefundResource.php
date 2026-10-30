<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A refund as staff see it (PRD-09 FR-12): the student's view plus its flag, the policy facts on the detail view,
 * and which decisions the back office may offer (the API still checks each one).
 *
 * @mixin Refund
 */
final class AdminRefundResource extends JsonResource
{
    private bool $withPolicy = false;

    private ?int $daysSincePayment = null;

    private int $refundWindowDays = 0;

    private ?int $progressPct = null;

    private int $maxProgressPct = 0;

    /**
     * @param  array{days_since_payment: int|null, refund_window_days: int, progress_pct: int|null, max_progress_pct: int}  $policy
     */
    public function withPolicy(array $policy): self
    {
        $this->withPolicy = true;
        $this->daysSincePayment = $policy['days_since_payment'];
        $this->refundWindowDays = $policy['refund_window_days'];
        $this->progressPct = $policy['progress_pct'];
        $this->maxProgressPct = $policy['max_progress_pct'];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'order_id' => $this->order_id,
            'order_number' => $this->order?->number,
            'course' => $this->item?->title_snapshot,
            'amount' => $this->amount()->toArray(),
            'status' => $this->status->value,
            'reason' => $this->reason,
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failure_code' => $this->failure_code,
            'created_at' => $this->created_at->toIso8601String(),
            /** @var string|null paid_twice, amount_mismatch, paid_after_reversal, payout_reversed, stuck */
            'flag' => $this->flag,
            'policy' => $this->policyFacts(),
            'can' => $this->can(),
        ];
    }

    /**
     * Days since payment, progress, and the limits — on the detail view only.
     *
     * @return array{days_since_payment: int|null, refund_window_days: int, progress_pct: int|null, max_progress_pct: int}|null
     */
    private function policyFacts(): ?array
    {
        if (! $this->withPolicy) {
            return null;
        }

        return [
            /** @var int|null */
            'days_since_payment' => $this->daysSincePayment,
            /** @var int */
            'refund_window_days' => $this->refundWindowDays,
            /** @var int|null */
            'progress_pct' => $this->progressPct,
            /** @var int */
            'max_progress_pct' => $this->maxProgressPct,
        ];
    }

    /**
     * @return array{approve: bool, reject: bool, retry: bool}
     */
    private function can(): array
    {
        $requested = $this->status === RefundStatus::Requested;

        return [
            'approve' => $requested,
            'reject' => $requested,
            'retry' => $this->canRetry(),
        ];
    }

    /** A failed payout can be tried again, unless the payer was already paid twice. */
    private function canRetry(): bool
    {
        return $this->status === RefundStatus::Failed && $this->flag !== 'paid_twice';
    }
}
