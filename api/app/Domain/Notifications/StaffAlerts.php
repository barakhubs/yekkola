<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Finance\Enums\PayoutBatchStatus;
use App\Domain\Finance\Models\PayoutBatch;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Enums\NotificationType;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * Staff alerts (PRD-10 FR-09), routed by permission: finance problems to finance staff, stuck payments to those
 * who follow commerce. In-app (back office) and email; the same alert is sent at most once per `REPEAT_HOURS`.
 */
final class StaffAlerts
{
    public const REPEAT_HOURS = 6;

    /** A payment still waiting this long (minutes) is stuck. */
    public const STUCK_AFTER_MINUTES = 30;

    public function __construct(private readonly Cache $cache) {}

    /** The daily ledger check found problems. */
    public function ledgerIntegrity(int $findings): void
    {
        $this->send('ledger', Permission::FinanceView, 'staff_alert.ledger', ['count' => (string) $findings], '/finance/ledger');
    }

    public function payoutBatchSaved(PayoutBatch $batch): void
    {
        if ($batch->wasChanged('status') && $batch->status === PayoutBatchStatus::PartiallyFailed) {
            $failed = $batch->payouts()->where('status', 'failed')->count();
            $this->send("payout-batch:{$batch->id}", Permission::FinancePayouts, 'staff_alert.payout_batch', ['count' => (string) $failed], "/finance/payouts/{$batch->id}");
        }
    }

    /** Checked on a schedule. */
    public function stuckPayments(): void
    {
        $stuck = DB::table('payments')->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value])
            ->where('created_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))->count();
        if ($stuck > 0) {
            $this->send('stuck-payments', Permission::CommerceView, 'staff_alert.stuck_payments', ['count' => (string) $stuck], '/orders?only=stuck');
        }
    }

    /**
     * @param  array<string, string>  $params
     */
    private function send(string $alert, Permission $permission, string $key, array $params, string $link): void
    {
        if (! $this->cache->add("staff-alert:{$alert}", true, now()->addHours(self::REPEAT_HOURS))) {
            return;
        }

        User::permission($permission->value)->where('status', UserStatus::Active)->get()
            ->each(fn (User $staff) => $staff->notify(
                (new PlatformNotification(NotificationType::StaffAlert, $key, $params, $link, app: 'admin'))->locale($staff->locale),
            ));
    }
}
