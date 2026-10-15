<?php

declare(strict_types=1);

namespace App\Domain\Professors\Console;

use App\Domain\Professors\Enums\ApplicationStatus;
use App\Domain\Professors\Models\ProfessorApplication;
use Illuminate\Console\Command;

/**
 * Expires "needs info" applications left unanswered (PRD-02 §7). Scheduled daily.
 */
final class ExpireStaleApplications extends Command
{
    protected $signature = 'professors:expire-stale-applications';

    protected $description = 'Expire professor applications whose info request went unanswered';

    public function handle(): int
    {
        $count = ProfessorApplication::query()
            ->where('status', ApplicationStatus::NeedsInfo)
            ->where('info_requested_at', '<=', now()->subDays((int) config('yekkola.professors.needs_info_expiry_days')))
            ->update(['status' => ApplicationStatus::Expired, 'decided_at' => now()]);

        $this->info("Expired {$count} application(s).");

        return self::SUCCESS;
    }
}
