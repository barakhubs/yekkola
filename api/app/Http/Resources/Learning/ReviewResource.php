<?php

declare(strict_types=1);

namespace App\Http\Resources\Learning;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review (PRD-06 FR-12). The public sees the author's first name and initial only; the author and the
 * course's professor also see the moderation status, and the professor the course it is about.
 *
 * @mixin Review
 */
final class ReviewResource extends JsonResource
{
    private bool $private = false;

    public function private(): self
    {
        $this->private = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'body' => $this->body,
            'author' => ['name' => User::shortNameOf($this->relationLoaded('user') ? $this->user?->name : null)],
            'created_at' => $this->created_at->toIso8601String(),
            'edited_at' => $this->updated_at->gt($this->created_at) ? $this->updated_at->toIso8601String() : null,
            'reply' => $this->professor_reply === null ? null : ['body' => $this->professor_reply, 'replied_at' => $this->replied_at?->toIso8601String()],
            'status' => $this->when($this->private, fn () => $this->status->value),
            'course' => $this->when($this->private && $this->relationLoaded('course'), fn () => [
                'id' => $this->course_id,
                'slug' => $this->course?->slug,
            ]),
        ];
    }
}
