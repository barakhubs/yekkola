<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Console;

use App\Domain\Authoring\Actions\RollupCourseStats;
use Illuminate\Console\Command;

/**
 * Hourly: recomputes today's and yesterday's per-course figures for professor analytics (late payments and
 * moderation land on their own day). `--days=N` backfills further.
 */
final class RollupCourseStatsCommand extends Command
{
    protected $signature = 'stats:rollup-courses {--days=2 : Days to recompute, today included}';

    protected $description = 'Recompute the professor analytics daily figures per course';

    public function handle(RollupCourseStats $rollup): int
    {
        $days = max(1, (int) $this->option('days'));
        for ($i = 0; $i < $days; $i++) {
            $rollup->handle(now()->subDays($i));
        }
        $this->info("Rolled up {$days} day(s).");

        return self::SUCCESS;
    }
}
