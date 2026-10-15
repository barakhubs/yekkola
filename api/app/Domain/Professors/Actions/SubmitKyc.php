<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Professors\Enums\KycDocumentType;
use App\Domain\Professors\Enums\KycStatus;
use App\Domain\Professors\Enums\KycSubmissionStatus;
use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\KycSubmission;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;

/**
 * The professor submits ID documents for verification (PRD-02 FR-08). One pending submission at a time
 * (profile row lock + partial unique index); a rejected check can be resubmitted.
 */
final class SubmitKyc
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(ProfessorProfile $profile, KycDocumentType $type, UploadedFile $front, ?UploadedFile $back, UploadedFile $selfie): KycSubmission
    {
        if ($type->hasBackSide() && $back === null) {
            throw ProfessorException::kycBackRequired();
        }

        try {
            return $this->db->transaction(function () use ($profile, $type, $front, $back, $selfie): KycSubmission {
                $locked = ProfessorProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

                if ($locked->kyc_status === KycStatus::Verified) {
                    throw ProfessorException::kycAlreadyVerified();
                }

                if ($locked->kycSubmissions()->where('status', KycSubmissionStatus::Pending)->exists()) {
                    throw ProfessorException::kycAlreadyPending();
                }

                $submission = $locked->kycSubmissions()->create([
                    'document_type' => $type,
                    'status' => KycSubmissionStatus::Pending,
                ]);

                foreach (['front' => $front, 'back' => $back, 'selfie' => $selfie] as $collection => $file) {
                    if ($file !== null) {
                        $submission->addMedia($file)
                            ->usingFileName($collection.'.'.$file->extension())
                            ->toMediaCollection($collection);
                    }
                }

                $locked->forceFill(['kyc_status' => KycStatus::Pending])->save();

                return $submission;
            });
        } catch (UniqueConstraintViolationException) {
            throw ProfessorException::kycAlreadyPending();
        }
    }
}
