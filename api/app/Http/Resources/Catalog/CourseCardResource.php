<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Authoring\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course card in the public catalogue (PRD-04 FR-04): always the live version. `rating` is null until
 * the course has enough reviews (BR-02, shown as "Nouveau").
 *
 * @mixin Course
 */
final class CourseCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $live = $this->liveVersion;
        $professor = $this->professor;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $live?->title,
            'subtitle' => $live?->subtitle,
            'cover_url' => $live?->coverUrl(),
            'professor' => [
                'slug' => $professor->slug,
                'display_name' => $professor->display_name,
                'avatar_url' => $professor->avatarUrl(),
                'is_verified' => $professor->isVerified(),
            ],
            'category' => $this->category ? ['slug' => $this->category->slug, 'name' => $this->category->name] : null,
            'language' => $this->language,
            'level' => $this->level->value,
            'audience' => $this->audience,
            'is_free' => $this->is_free,
            'price' => $this->price()?->toArray(),
            'rating' => $this->publicRating(),
            'rating_count' => $this->rating_count,
            'enrollment_count' => $this->enrollment_count,
            'total_duration_seconds' => $this->total_duration_seconds,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
