<?php

declare(strict_types=1);

namespace App\Domain\Finance\Console;

use App\Domain\Finance\Actions\BuildPayoutBatch;
use App\Domain\Finance\Exceptions\FinanceException;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Platform\Settings\PayoutSettings;
use App\Domain\Shared\Enums\Currency;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Daily: on the days the payout schedule setting says (weekly: Mondays; bi-weekly: Mondays of even ISO weeks;
 * monthly: the 1st), builds a draft batch per enabled currency for finance to review (PRD-08 FR-06).
 */
final class BuildDuePayoutBatches extends Command
{
    protected $signature = 'finance:build-payout-batches {--force : Build today whatever the schedule}';

    protected $description = 'Build draft payout batches on the days the payout schedule says';

    public function handle(BuildPayoutBatch $build, PayoutSettings $payouts, CommerceSettings $commerce): int
    {
        if (! $this->option('force') && ! self::isDue($payouts->schedule, now())) {
            return self::SUCCESS;
        }

        foreach ($commerce->enabled_currencies as $code) {
            $currency = Currency::tryFrom((string) $code);
            if ($currency === null) {
                continue;
            }
            try {
                $batch = $build->handle($currency);
                $this->info("Built batch {$batch->id} ({$currency->value}).");
            } catch (FinanceException $e) {
                $this->line("{$currency->value}: {$e->errorCode}"); // nothing to pay, or a batch still open
            }
        }

        return self::SUCCESS;
    }

    public static function isDue(string $schedule, CarbonInterface $day): bool
    {
        return match ($schedule) {
            'weekly' => $day->isMonday(),
            'biweekly' => $day->isMonday() && $day->isoWeek() % 2 === 0,
            default => $day->day === 1, // monthly
        };
    }
}
