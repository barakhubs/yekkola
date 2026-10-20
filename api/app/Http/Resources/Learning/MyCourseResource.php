<?php

declare(strict_types=1);

namespace App\Http\Resources\Learning;

use App\Domain\Learning\Models\Enrollment;
use App\Http\Resources\Catalog\CourseCardResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course in "My courses" (PRD-06 FR-01): the card, my progress, and where to continue.
 *
 * @mixin Enrollment
 */
final class MyCourseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'enrollment_id' => $this->id,
            'source' => $this->source->value,
            'progress_pct' => $this->progress_pct,
            'continue_lesson_uid' => $this->last_lesson_uid,
            'enrolled_at' => $this->enrolled_at->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'course' => new CourseCardResource($this->course),
        ];
    }
}
