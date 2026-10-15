<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\Section;
use App\Domain\Catalog\DownloadEstimate;
use App\Http\Resources\PublicProfessorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public course page (PRD-04 FR-05): live version, curriculum outline (no lesson content, no quiz
 * answers), professor block, and the estimated download size at the lowest tier.
 *
 * @mixin Course
 */
final class CourseDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $live = $this->liveVersion;
        $estimate = app(DownloadEstimate::class);
        $downloadBytes = 0;

        $sections = $live->sections->map(function (Section $section) use ($estimate, &$downloadBytes): array {
            return [
                'id' => $section->id,
                'title' => $section->title,
                'lessons' => $section->lessons->map(function (Lesson $lesson) use ($estimate, &$downloadBytes): array {
                    $bytes = $estimate->bytes($lesson);
                    $downloadBytes += $bytes;

                    return [
                        'uid' => $lesson->uid,
                        'type' => $lesson->type->value,
                        'title' => $lesson->title,
                        'duration_seconds' => $lesson->duration_seconds,
                        'is_preview' => $lesson->is_preview,
                        'download_bytes' => $bytes,
                    ];
                })->all(),
            ];
        })->all();

        $category = $this->category;

        return [
            ...(new CourseCardResource($this->resource))->toArray($request),
            'description' => $live->description,
            'outcomes' => $live->outcomes,
            'requirements' => $live->requirements,
            'category' => $category ? [
                'slug' => $category->slug,
                'name' => $category->name,
                'parent' => $category->parent ? ['slug' => $category->parent->slug, 'name' => $category->parent->name] : null,
            ] : null,
            'professor' => new PublicProfessorResource($this->professor),
            'sections' => $sections,
            'lesson_count' => $live->sections->sum(fn (Section $s) => $s->lessons->count()),
            'download_bytes' => $downloadBytes,
            'updated_at' => $live->reviewed_at?->toIso8601String(),
        ];
    }
}
