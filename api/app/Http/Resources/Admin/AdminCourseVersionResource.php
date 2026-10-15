<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Authoring\Models\Lesson;
use App\Http\Resources\Authoring\CourseVersionResource;
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
    private bool $withCurriculum = false;

    public function withCurriculum(): self
    {
        $this->withCurriculum = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $course = $this->course;
        $professor = $course->professor;

        $data = [
            'id' => $this->id,
            'number' => $this->number,
            'state' => $this->state->value,
            'title' => $this->title,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'is_update' => $course->live_version_id !== null && $course->live_version_id !== $this->id,
            'course' => (new StudioCourseResource($course))->resolve($request),
            'professor' => [
                'id' => $professor->id,
                'display_name' => $professor->display_name,
                'slug' => $professor->slug,
                'is_verified' => $professor->isVerified(),
            ],
        ];

        if (! $this->withCurriculum) {
            return $data;
        }

        return array_merge($data, [
            'version' => (new CourseVersionResource($this->resource))->resolve($request),
            'changes' => $this->changesSinceLive(),
        ]);
    }

    /**
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}|null
     */
    private function changesSinceLive(): ?array
    {
        $live = $this->course->liveVersion;
        if ($live === null || $live->id === $this->id) {
            return null;
        }

        $mine = $this->lessons->keyBy('uid');
        $theirs = $live->lessons()->get()->keyBy('uid');

        return [
            'added' => $mine->diffKeys($theirs)->pluck('title')->values()->all(),
            'removed' => $theirs->diffKeys($mine)->pluck('title')->values()->all(),
            'changed' => $mine->intersectByKeys($theirs)
                ->filter(fn (Lesson $l) => $this->lessonChanged($l, $theirs[$l->uid]))
                ->pluck('title')->values()->all(),
        ];
    }

    private function lessonChanged(Lesson $a, Lesson $b): bool
    {
        return $a->title !== $b->title
            || $a->description !== $b->description
            || $a->media_asset_id !== $b->media_asset_id
            || $a->is_preview !== $b->is_preview
            || $a->updated_at?->gt($this->created_at);
    }
}
