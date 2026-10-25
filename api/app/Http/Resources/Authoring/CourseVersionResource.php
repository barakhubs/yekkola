<?php

declare(strict_types=1);

namespace App\Http\Resources\Authoring;

use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A version with its full curriculum, for the studio and the review screen. Includes correct quiz answers,
 * so it is never used for students.
 *
 * @mixin CourseVersion
 */
final class CourseVersionResource extends JsonResource
{
    /** @var list<string>|null */
    private ?array $checklist = null;

    /**
     * @param  list<string>  $missing
     */
    public function withChecklist(array $missing): self
    {
        $this->checklist = $missing;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'number' => $this->number,
            'state' => $this->state->value,
            'is_editable' => $this->isEditable(),
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'outcomes' => $this->outcomes,
            'requirements' => $this->requirements,
            'changelog' => $this->changelog,
            'cover_url' => $this->coverUrl(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_notes' => $this->review_notes,
            'sections' => $this->sections->map(fn (Section $section) => [
                'id' => $section->id,
                'title' => $section->title,
                'position' => $section->position,
                'lessons' => $section->lessons->map(fn (Lesson $lesson) => new StudioLessonResource($lesson))->values()->all(),
            ])->values()->all(),
            // Only on the builder's GET (the submit checklist, PRD-03 FR-10); null elsewhere.
            'checklist' => $this->checklist === null ? null : ['ready' => $this->checklist === [], 'missing' => $this->missing()],
        ];
    }

    /**
     * What still blocks submission (PRD-03 FR-10): title, description, outcomes, cover, category, pricing,
     * sections, lessons, lesson_content.
     *
     * @return list<string>
     */
    private function missing(): array
    {
        return $this->checklist ?? [];
    }
}
