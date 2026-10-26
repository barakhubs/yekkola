<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Authoring\Models\Lesson;
use App\Http\Resources\Authoring\CourseVersionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The review screen (PRD-09 FR-07): the queue item plus the whole version (curriculum, quiz answers) and what
 * changed since the live version.
 *
 * @mixin CourseVersion
 */
final class AdminCourseVersionDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new AdminCourseVersionResource($this->resource))->toArray($request),
            'version' => new CourseVersionResource($this->resource),
            'changes' => $this->changesSinceLive(),
        ];
    }

    /**
     * Lessons added, removed or changed compared with the live version, by title; null for a first version.
     *
     * @return array{added: array<int, string>, removed: array<int, string>, changed: array<int, string>}|null
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
            'added' => $this->titles($mine->diffKeys($theirs)),
            'removed' => $this->titles($theirs->diffKeys($mine)),
            'changed' => $this->titles($mine->intersectByKeys($theirs)->filter(fn (Lesson $l) => $this->lessonChanged($l, $theirs[$l->uid]))),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<array-key, Lesson>  $lessons
     * @return array<int, string>
     */
    private function titles(\Illuminate\Support\Collection $lessons): array
    {
        return $lessons->map(fn (Lesson $l): string => $l->title)->values()->all();
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
