<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Console;

use App\Domain\Notifications\StaffAlerts;
use Illuminate\Console\Command;

/** Alerts commerce staff when mobile-money payments stay pending too long (PRD-10 FR-09). */
final class CheckStuckPayments extends Command
{
    protected $signature = 'alerts:stuck-payments';

    protected $description = 'Alert staff about payments pending for too long';

    public function handle(StaffAlerts $alerts): int
    {
        $alerts->stuckPayments();

        return self::SUCCESS;
    }
}
