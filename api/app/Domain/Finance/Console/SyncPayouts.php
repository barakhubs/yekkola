<?php

declare(strict_types=1);

namespace App\Domain\Finance\Console;

use App\Domain\Finance\Actions\PayoutLifecycle;
use App\Domain\Finance\Enums\PayoutBatchStatus;
use App\Domain\Finance\Enums\PayoutStatus;
use App\Domain\Finance\Models\Payout;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Every 5 minutes (PRD-08 FR-08): re-queries payouts in flight without news for 2 minutes (counted from the send,
 * so a send still waiting for its answer is left alone), in case a webhook was missed; and sends again lines of an
 * approved batch still pending after 10 minutes (a lost or exhausted job), so a batch never stays open for ever.
 * Each row is isolated: one failure never stops the run.
 */
final class SyncPayouts extends Command
{
    protected $signature = 'finance:sync-payouts';

    protected $description = 'Re-check payouts in flight with the gateway';

    private const IN_FLIGHT_AFTER_MINUTES = 2;

    private const PENDING_AFTER_MINUTES = 10;

    public function handle(PayoutLifecycle $payouts): int
    {
        $checked = 0;
        Payout::query()
            ->where('status', PayoutStatus::Processing)
            ->whereRaw('COALESCE(last_checked_at, sent_at, created_at) < ?', [now()->subMinutes(self::IN_FLIGHT_AFTER_MINUTES)])
            ->lazyById(100)
            ->each(function (Payout $payout) use ($payouts, &$checked): void {
                try {
                    $payouts->sync($payout);
                    $checked++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $resent = 0;
        Payout::query()
            ->where('status', PayoutStatus::Pending)
            ->whereHas('batch', fn (Builder $q) => $q->where('status', PayoutBatchStatus::Processing)->where('approved_at', '<', now()->subMinutes(self::PENDING_AFTER_MINUTES)))
            ->lazyById(100)
            ->each(function (Payout $payout) use ($payouts, &$resent): void {
                try {
                    $payouts->execute($payout);
                    $resent++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $this->info("Checked {$checked} payout(s), sent {$resent} left pending.");

        return self::SUCCESS;
    }
}
