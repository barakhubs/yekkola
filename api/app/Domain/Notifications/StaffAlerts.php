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
use App\Domain\Platform\Settings\CommerceSettings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * Staff alerts (PRD-10 FR-09), routed by permission: finance problems to finance staff, stuck payments to those
 * who follow commerce. In-app (back office) and email; the same alert is sent at most once per `REPEAT_HOURS`,
 * decided only once the change that raised it is committed.
 */
final class StaffAlerts
{
    public const REPEAT_HOURS = 6;

    public function __construct(
        private readonly Cache $cache,
        private readonly CommerceSettings $commerce,
    ) {}

    /** The daily ledger check found problems. */
    public function ledgerIntegrity(int $findings): void
    {
        $this->send('ledger', Permission::FinanceView, 'staff_alert.ledger', ['count' => (string) $findings], '/finance/ledger');
    }

    public function payoutBatchSaved(PayoutBatch $batch): void
    {
        if ($batch->wasChanged('status') && $batch->status === PayoutBatchStatus::PartiallyFailed) {
            DB::afterCommit(function () use ($batch): void {
                $failed = $batch->payouts()->where('status', 'failed')->count();
                // Keyed by the failure count: a retry that fails again is a new alert.
                $this->send("payout-batch:{$batch->id}:{$failed}", Permission::FinancePayouts, 'staff_alert.payout_batch', ['count' => (string) $failed], "/finance/payouts/{$batch->id}");
            });
        }
    }

    /**
     * Payments still waiting well past the order payment timeout (a platform setting) plus the grace below.
     * Checked on a schedule.
     */
    public function stuckPayments(): void
    {
        $minutes = $this->commerce->order_payment_timeout_minutes + (int) config('yekkola.alerts.stuck_payment_grace_minutes');
        $stuck = DB::table('payments')->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value])
            ->where('created_at', '<', now()->subMinutes($minutes))->count();
        if ($stuck > 0) {
            $this->send('stuck-payments', Permission::CommerceView, 'staff_alert.stuck_payments', ['count' => (string) $stuck, 'minutes' => (string) $minutes], '/orders?only=stuck');
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
