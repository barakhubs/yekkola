<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Professors\Actions\ReviewApplication;
use App\Domain\Professors\Models\ProfessorApplication;
use App\Http\Resources\ProfessorApplicationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An application as staff see it (PRD-02 FR-05, PRD-09 FR-06): the applicant's view plus who applied (masked
 * phone), the decision note whatever the outcome, and who is reviewing it.
 *
 * @mixin ProfessorApplication
 */
final class AdminProfessorApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new ProfessorApplicationResource($this->resource))->toArray($request),
            'applicant' => [
                'id' => $this->user_id,
                'name' => $this->user?->name,
                'phone' => $this->user?->maskedPhone(),
                'anonymised' => $this->user?->isAnonymised() ?? true,
            ],
            'decision_note' => $this->decision_note,
            'reviewer_id' => $this->reviewer_id,
            'review_started_at' => $this->review_started_at?->toIso8601String(),
            'terms_version' => $this->terms_version,
            'can' => $this->can($request),
        ];
    }

    /**
     * What the signed-in staff member may do with it now (the rules live in ReviewApplication).
     *
     * @return array{start_review: bool, decide: bool}
     */
    private function can(Request $request): array
    {
        $staff = $request->user();
        $review = app(ReviewApplication::class);

        return [
            'start_review' => $staff instanceof User && $review->canStartReview($this->resource, $staff),
            'decide' => $staff instanceof User && $review->canDecide($this->resource, $staff),
        ];
    }
}
