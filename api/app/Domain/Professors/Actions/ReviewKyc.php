<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Professors\Enums\KycStatus;
use App\Domain\Professors\Enums\KycSubmissionStatus;
use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\KycSubmission;
use Illuminate\Database\ConnectionInterface;

/**
 * Staff verify or reject an identity check (PRD-02 FR-08, PRD-09 FR-06). Verifying grants the
 * "Vérifié" badge and unblocks payouts (BR-01).
 */
final class ReviewKyc
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecordAudit $recordAudit,
    ) {}

    public function verify(KycSubmission $submission, User $staff): KycSubmission
    {
        return $this->decide($submission, $staff, KycSubmissionStatus::Verified, null);
    }

    public function reject(KycSubmission $submission, User $staff, string $reason): KycSubmission
    {
        return $this->decide($submission, $staff, KycSubmissionStatus::Rejected, $reason);
    }

    private function decide(KycSubmission $submission, User $staff, KycSubmissionStatus $outcome, ?string $reason): KycSubmission
    {
        if ($submission->status !== KycSubmissionStatus::Pending) {
            throw ProfessorException::kycNotPending();
        }

        $profile = $submission->profile;
        if ($profile->user_id === $staff->id) {
            throw ProfessorException::ownApplication();
        }

        return $this->db->transaction(function () use ($submission, $profile, $staff, $outcome, $reason): KycSubmission {
            $submission->forceFill([
                'status' => $outcome,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $profile->forceFill($outcome === KycSubmissionStatus::Verified
                ? ['kyc_status' => KycStatus::Verified, 'verified_at' => now()]
                : ['kyc_status' => KycStatus::Rejected])->save();

            $this->recordAudit->handle('kyc.'.$outcome->value, $submission, array_filter(['reason' => $reason]), causer: $staff);

            return $submission;
        });
    }
}
