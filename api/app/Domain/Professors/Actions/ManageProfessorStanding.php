<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Professors\Enums\ProfessorStatus;
use App\Domain\Professors\Models\ProfessorProfile;
use InvalidArgumentException;

/**
 * Admin controls over a professor (PRD-02 FR-11, BR-02/03): suspend, reinstate, revenue-share override.
 * Suspension hides the professor and their courses from the catalogue and holds payouts; earnings stay
 * in the ledger. Existing students keep access unless content is taken down.
 */
final class ManageProfessorStanding
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    public function suspend(ProfessorProfile $profile, User $staff, string $reason): ProfessorProfile
    {
        $profile->forceFill([
            'status' => ProfessorStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ])->save();

        $this->recordAudit->handle('professor.suspended', $profile, ['reason' => $reason], causer: $staff);

        return $profile;
    }

    public function reinstate(ProfessorProfile $profile, User $staff, string $reason): ProfessorProfile
    {
        $profile->forceFill([
            'status' => ProfessorStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        $this->recordAudit->handle('professor.reinstated', $profile, ['reason' => $reason], causer: $staff);

        return $profile;
    }

    /**
     * Applies to future sales only — each sale snapshots the share it used.
     */
    public function setRevenueShare(ProfessorProfile $profile, User $staff, ?int $bps, string $reason): ProfessorProfile
    {
        if ($bps !== null && ($bps < 0 || $bps > 10_000)) {
            throw new InvalidArgumentException('Revenue share must be between 0 and 10000 basis points.');
        }

        $before = $profile->revenue_share_bps;
        $profile->forceFill(['revenue_share_bps' => $bps])->save();

        $this->recordAudit->handle('professor.revenue_share_changed', $profile, [
            'before' => $before,
            'after' => $bps,
            'reason' => $reason,
        ], causer: $staff);

        return $profile;
    }
}
