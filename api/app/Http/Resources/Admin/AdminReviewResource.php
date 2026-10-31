<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Learning\Enums\ReviewStatus;
use App\Domain\Learning\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review as moderators see it (PRD-09 FR-10): who wrote it on which course, and whether it is hidden and why.
 *
 * @mixin Review
 */
final class AdminReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'body' => $this->body,
            'professor_reply' => $this->professor_reply,
            'status' => $this->status->value,
            'author' => ['id' => $this->user_id, 'name' => $this->user?->name],
            'course' => ['id' => $this->course_id, 'slug' => $this->course?->slug, 'title' => $this->course?->liveVersion?->title],
            'hidden_at' => $this->hidden_at?->toIso8601String(),
            'hidden_reason' => $this->hidden_reason,
            'created_at' => $this->created_at->toIso8601String(),
            'can' => ['hide' => $this->status === ReviewStatus::Visible, 'restore' => $this->status === ReviewStatus::Hidden],
        ];
    }
}
