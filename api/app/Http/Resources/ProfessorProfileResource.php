<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Authoring\Models\Course;
use App\Domain\Professors\Enums\LegalDocument;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Professors\Models\TermsAcceptance;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The professor's own profile in the studio (private fields included, payout number masked).
 *
 * @mixin ProfessorProfile
 */
final class ProfessorProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = LegalDocument::ProfessorAgreement->currentVersion();

        return [
            ...(new PublicProfessorResource($this->resource))->toArray($request),
            'status' => $this->status->value,
            'suspension_reason' => $this->suspension_reason,
            'can_change_slug' => $this->canChangeSlug(),
            'kyc_status' => $this->kyc_status->value,
            'payout_method' => $this->hasPayoutMethod() ? [
                'rail' => $this->payout_rail?->value,
                'phone' => PhoneNumber::fromString((string) $this->payout_msisdn)->masked(),
                'name' => $this->payout_name,
                'updated_at' => $this->payout_method_updated_at?->toIso8601String(),
            ] : null,
            'payout_hold_until' => $this->payout_hold_until?->isFuture() ? $this->payout_hold_until->toIso8601String() : null,
            'is_payable' => $this->isPayable(),
            'terms' => [
                'current_version' => $version,
                'accepted' => TermsAcceptance::hasAcceptedCurrent($this->user_id, LegalDocument::ProfessorAgreement),
            ],
            // The studio's onboarding checklist (PRD-02 §6): profile, identity, payout method, first course.
            'onboarding' => [
                'profile_complete' => $this->profileComplete(),
                'kyc_verified' => $this->isVerified(),
                'payout_method_set' => $this->hasPayoutMethod(),
                'first_course' => $this->hasSubmittedCourse(),
            ],
        ];
    }

    /** Photo, headline and bio: what students see first on the public page. */
    private function profileComplete(): bool
    {
        return filled($this->bio) && filled($this->headline) && $this->avatarUrl() !== null;
    }

    /** At least one course sent for review or live. */
    private function hasSubmittedCourse(): bool
    {
        return Course::query()
            ->where('professor_profile_id', $this->id)
            ->where(fn ($q) => $q->whereNotNull('live_version_id')->orWhere('status', CourseStatus::InReview))
            ->exists();
    }
}
