<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\PayoutBatchStatus;
use App\Domain\Finance\Enums\PayoutStatus;
use App\Domain\Finance\Exceptions\FinanceException;
use App\Domain\Finance\Jobs\ExecutePayout;
use App\Domain\Finance\Models\Payout;
use App\Domain\Finance\Models\PayoutBatch;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Database\ConnectionInterface;

/**
 * Finance review of a draft batch (PRD-08 FR-07): leave a line out with a reason, then approve, which sends every
 * remaining line on the `payments` queue. Staff never approve a batch that pays themselves.
 */
final class ReviewPayoutBatch
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecordAudit $recordAudit,
        private readonly PayoutLifecycle $lifecycle,
    ) {}

    public function exclude(Payout $payout, User $staff, string $reason): Payout
    {
        return $this->db->transaction(function () use ($payout, $staff, $reason): Payout {
            $batch = PayoutBatch::query()->whereKey($payout->payout_batch_id)->lockForUpdate()->firstOrFail();
            $payout = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== PayoutBatchStatus::Draft) {
                throw FinanceException::batchNotDraft();
            }
            if ($payout->status !== PayoutStatus::Pending) {
                throw FinanceException::payoutNotPending();
            }

            $payout->forceFill(['status' => PayoutStatus::Excluded, 'excluded_reason' => $reason])->save();
            $this->recordAudit->handle('payout.excluded', $payout, ['reason' => $reason, 'batch_id' => $batch->id], causer: $staff);

            return $payout;
        });
    }

    public function approve(PayoutBatch $batch, User $staff): PayoutBatch
    {
        $batch = $this->db->transaction(function () use ($batch, $staff): PayoutBatch {
            $batch = PayoutBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== PayoutBatchStatus::Draft) {
                throw FinanceException::batchNotDraft();
            }
            $ownProfile = ProfessorProfile::query()->where('user_id', $staff->id)->value('id');
            if ($ownProfile !== null && $batch->payouts()->where('professor_profile_id', $ownProfile)->where('status', PayoutStatus::Pending)->exists()) {
                throw FinanceException::ownPayout();
            }

            $batch->forceFill(['status' => PayoutBatchStatus::Processing, 'approved_by' => $staff->id, 'approved_at' => now()])->save();
            $this->recordAudit->handle('payout_batch.approved', $batch, ['currency' => $batch->currency], causer: $staff);

            foreach ($batch->payouts()->where('status', PayoutStatus::Pending)->pluck('id') as $id) {
                ExecutePayout::dispatch((string) $id)->afterCommit();
            }

            return $batch;
        });

        $this->lifecycle->settleBatch($batch); // every line left out: nothing to send

        return $batch->refresh();
    }
}
