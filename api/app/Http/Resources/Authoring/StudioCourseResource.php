<?php

declare(strict_types=1);

namespace App\Http\Resources\Authoring;

use App\Domain\Authoring\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course as its professor (and staff) see it: settings plus live/draft version summaries.
 *
 * @mixin Course
 */
final class StudioCourseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $live = $this->liveVersion;
        $draft = $this->draftVersion;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'title' => $draft->title ?? $live?->title,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'slug' => $this->category->slug,
                'name' => $this->category->name,
            ] : null,
            'language' => $this->language,
            'level' => $this->level->value,
            'audience' => $this->audience,
            'is_free' => $this->is_free,
            'price' => $this->price()?->toArray(),
            'revenue_share_bps' => $this->revenue_share_bps,
            'live_version' => $live ? [
                'id' => $live->id,
                'number' => $live->number,
                'title' => $live->title,
                'approved_at' => $live->reviewed_at?->toIso8601String(),
            ] : null,
            'draft_version' => $draft ? [
                'id' => $draft->id,
                'number' => $draft->number,
                'state' => $draft->state->value,
                'submitted_at' => $draft->submitted_at?->toIso8601String(),
                'review_notes' => $draft->review_notes,
            ] : null,
            'total_duration_seconds' => $this->total_duration_seconds,
            'enrollment_count' => $this->enrollment_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
