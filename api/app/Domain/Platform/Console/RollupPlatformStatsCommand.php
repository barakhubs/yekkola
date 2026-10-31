<?php

declare(strict_types=1);

namespace App\Domain\Platform\Console;

use App\Domain\Platform\Actions\RollupPlatformStats;
use Illuminate\Console\Command;

/**
 * Hourly: recomputes today's and yesterday's dashboard figures (late payments, refunds and payouts land on their
 * own day). `--days=N` backfills further.
 */
final class RollupPlatformStatsCommand extends Command
{
    protected $signature = 'stats:rollup-platform {--days=2 : Days to recompute, today included}';

    protected $description = 'Recompute the admin dashboard daily figures';

    public function handle(RollupPlatformStats $rollup): int
    {
        $days = max(1, (int) $this->option('days'));
        for ($i = 0; $i < $days; $i++) {
            $rollup->handle(now()->subDays($i));
        }
        $this->info("Rolled up {$days} day(s).");

        return self::SUCCESS;
    }
}
