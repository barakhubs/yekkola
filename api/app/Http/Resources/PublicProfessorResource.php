<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Authoring\Models\Course;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Resources\Catalog\CourseCardResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A professor's public page (PRD-02 FR-12, PRD-04 FR-07). No contact or payout details.
 *
 * @mixin ProfessorProfile
 */
final class PublicProfessorResource extends JsonResource
{
    /** @var Collection<int, Course>|null */
    private ?Collection $courses = null;

    /**
     * Adds the professor's listed courses (the professor page, PRD-04 FR-07).
     *
     * @param  Collection<int, Course>  $courses
     */
    public function withCourses(Collection $courses): self
    {
        $this->courses = $courses;

        return $this;
    }

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
            // What to display: like courses, nothing until there are enough reviews (PRD-04 BR-02).
            'rating' => (int) $this->getAttribute('rating_count') >= Course::MIN_REVIEWS_FOR_RATING
                ? round((float) $this->getAttribute('rating_avg'), 1)
                : null,
            'student_count' => (int) $this->getAttribute('student_count'),
            'member_since' => $this->created_at->toIso8601String(),
            'courses' => $this->when($this->courses !== null, fn () => CourseCardResource::collection($this->courses)),
        ];
    }
}
