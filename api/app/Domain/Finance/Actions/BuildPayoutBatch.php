<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\PayoutBatchStatus;
use App\Domain\Finance\Enums\PayoutStatus;
use App\Domain\Finance\Exceptions\FinanceException;
use App\Domain\Finance\Models\Payout;
use App\Domain\Finance\Models\PayoutBatch;
use App\Domain\Finance\ProfessorBalances;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\Settings\PayoutSettings;
use App\Domain\Professors\Enums\KycStatus;
use App\Domain\Professors\Enums\ProfessorStatus;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Shared\Enums\Currency;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds a draft payout batch in one currency (PRD-08 FR-06): every professor who is active, KYC-verified, has a
 * payout method, isn't under the payout-method-change hold, and whose available balance reaches the minimum. Each
 * line pays the balance rounded down to what the currency can be sent in (whole francs for CDF); the rest stays
 * available for the next run. Nothing is posted until a payout succeeds.
 */
final class BuildPayoutBatch
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PayoutSettings $settings,
        private readonly RecordAudit $recordAudit,
    ) {}

    public function handle(Currency $currency, ?User $staff = null): PayoutBatch
    {
        $open = PayoutBatch::query()->where('currency', $currency->value)
            ->whereIn('status', [PayoutBatchStatus::Draft, PayoutBatchStatus::Processing])->first();
        if ($open !== null) {
            throw FinanceException::batchOpen($open->id);
        }

        return $this->db->transaction(function () use ($currency, $staff): PayoutBatch {
            $balances = ProfessorBalances::available($currency);
            $minimum = max(1, (int) ($this->settings->minimum_minor[$currency->value] ?? 0));
            $step = $currency->collectionStepMinor();

            $professors = ProfessorProfile::query()
                ->whereIn('id', array_keys(array_filter($balances, fn (int $b) => $b >= $minimum)))
                ->where('status', ProfessorStatus::Active)
                ->where('kyc_status', KycStatus::Verified)
                ->whereNotNull('payout_rail')->whereNotNull('payout_msisdn')
                ->where(fn (Builder $q) => $q->whereNull('payout_hold_until')->orWhere('payout_hold_until', '<=', now()))
                ->orderBy('id')
                ->get();

            $batch = PayoutBatch::query()->create([
                'status' => PayoutBatchStatus::Draft,
                'currency' => $currency->value,
                'created_by' => $staff?->id,
            ]);

            foreach ($professors as $professor) {
                if (PayoutLifecycle::recentlyFailed($professor->id, $currency->value)) {
                    continue; // paid in a later run, once that failure is certain
                }
                $amount = intdiv($balances[$professor->id], $step) * $step;
                if ($amount < $minimum) {
                    continue;
                }
                $payout = new Payout([
                    'payout_batch_id' => $batch->id,
                    'professor_profile_id' => $professor->id,
                    'amount_minor' => $amount,
                    'currency' => $currency->value,
                    'rail' => $professor->payout_rail,
                    'msisdn' => $professor->payout_msisdn,
                    'name' => (string) ($professor->payout_name ?? $professor->display_name),
                    'status' => PayoutStatus::Pending,
                ]);
                $payout->id = $payout->newUniqueId();
                $payout->reference = 'P-'.$payout->id;
                $payout->save();
            }

            if (! $batch->payouts()->exists()) {
                throw FinanceException::nothingToPay(); // rolls the empty batch back
            }

            $this->recordAudit->handle('payout_batch.built', $batch, ['currency' => $currency->value], causer: $staff);

            return $batch;
        });
    }
}
