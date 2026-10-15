<?php

declare(strict_types=1);

namespace App\Domain\Professors\Console;

use App\Domain\Platform\Actions\RecordAudit;
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

    public function handle(RecordAudit $audit): int
    {
        $cutoff = now()->subDays((int) config('yekkola.professors.needs_info_expiry_days'));
        $count = 0;

        // Only applications the applicant hasn't touched since the cutoff.
        ProfessorApplication::query()
            ->where('status', ApplicationStatus::NeedsInfo)
            ->where('info_requested_at', '<=', $cutoff)
            ->where('updated_at', '<=', $cutoff)
            ->lazyById()
            ->each(function (ProfessorApplication $application) use ($audit, &$count): void {
                $application->forceFill(['status' => ApplicationStatus::Expired, 'decided_at' => now()])->save();
                $audit->handle('professor_application.expired', $application);
                $count++;
            });

        $this->info("Expired {$count} application(s).");

        return self::SUCCESS;
    }
}
