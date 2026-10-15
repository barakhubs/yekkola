<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Professors\Enums\KycStatus;
use App\Domain\Professors\Enums\KycSubmissionStatus;
use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\KycSubmission;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Database\ConnectionInterface;

/**
 * Staff verify or reject an identity check (PRD-02 FR-08, PRD-09 FR-06). Verifying grants the verified
 * badge and unblocks payouts (BR-01). The submission is locked so concurrent decisions can't both apply,
 * and a verified profile is never downgraded by rejecting some other submission.
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
        if ($submission->profile->user_id === $staff->id) {
            throw ProfessorException::ownApplication();
        }

        return $this->db->transaction(function () use ($submission, $staff, $outcome, $reason): KycSubmission {
            $profile = ProfessorProfile::query()->whereKey($submission->professor_profile_id)->lockForUpdate()->firstOrFail();
            $locked = KycSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== KycSubmissionStatus::Pending) {
                throw ProfessorException::kycNotPending();
            }

            $locked->forceFill([
                'status' => $outcome,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            if ($outcome === KycSubmissionStatus::Verified) {
                $profile->forceFill(['kyc_status' => KycStatus::Verified, 'verified_at' => now()])->save();
            } elseif ($profile->kyc_status !== KycStatus::Verified) {
                $profile->forceFill(['kyc_status' => KycStatus::Rejected])->save();
            }

            $this->recordAudit->handle('kyc.'.$outcome->value, $locked, array_filter(['reason' => $reason]), causer: $staff);

            return $locked->setRelation('profile', $profile);
        });
    }
}
