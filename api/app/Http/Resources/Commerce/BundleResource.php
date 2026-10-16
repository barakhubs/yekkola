<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Authoring\Models\Course;
use App\Domain\Commerce\Models\Bundle;
use App\Http\Resources\Catalog\CourseCardResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bundle with its courses (PRD-05 FR-13). `list_total` is what the courses cost separately.
 *
 * @mixin Bundle
 */
final class BundleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $courses = $this->courses;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'price' => $this->price()->toArray(),
            'list_total' => ['amount_minor' => (int) $courses->sum(fn (Course $c) => (int) $c->price_minor), 'currency' => $this->currency],
            'courses' => CourseCardResource::collection($courses),
        ];
    }
}
