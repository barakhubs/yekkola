<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Authoring\Models\CourseVersion;
use App\Http\Resources\Authoring\StudioCourseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A version in the review queue (PRD-09 FR-07): curriculum, course settings, professor, and what changed
 * since the live version (lessons matched by `uid`).
 *
 * @mixin CourseVersion
 */
final class AdminCourseVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $course = $this->course;
        $professor = $course->professor;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'state' => $this->state->value,
            'title' => $this->title,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'is_update' => $this->isUpdate(),
            'course' => new StudioCourseResource($course),
            'professor' => [
                'id' => $professor->id,
                'display_name' => $professor->display_name,
                'slug' => $professor->slug,
                'is_verified' => $professor->isVerified(),
            ],
        ];
    }

    /** A change to a course that is already live (vs its first version). */
    private function isUpdate(): bool
    {
        return $this->course->live_version_id !== null && $this->course->live_version_id !== $this->id;
    }
}
