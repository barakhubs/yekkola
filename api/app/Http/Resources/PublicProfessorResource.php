<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A professor's public page (PRD-02 FR-12, PRD-04 FR-07). No contact or payout details.
 *
 * @mixin ProfessorProfile
 */
final class PublicProfessorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'display_name' => $this->display_name,
            'headline' => $this->headline,
            'bio' => $this->bio,
            'subjects' => $this->subjects,
            'links' => $this->links,
            'avatar_url' => $this->avatarUrl(),
            'province' => $this->province ? new ProvinceResource($this->province) : null,
            'is_verified' => $this->isVerified(),
            'rating_avg' => (float) $this->getAttribute('rating_avg'),
            'rating_count' => (int) $this->getAttribute('rating_count'),
            'student_count' => (int) $this->getAttribute('student_count'),
            'member_since' => $this->created_at->toIso8601String(),
        ];
    }
}
