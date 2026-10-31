<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Authoring\Models\Course;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course in the back office (PRD-09 FR-08), with the availability decisions the API would accept.
 *
 * @mixin Course
 */
final class AdminCourseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            /** @var string|null The live version's title (or the draft's, before the first approval). */
            'title' => $this->liveVersion->title ?? $this->draftVersion->title ?? null,
            'professor' => ['id' => $this->professor_profile_id, 'display_name' => $this->professor?->display_name],
            /** @var string|null */
            'category' => $this->category?->name,
            'status' => $this->status->value,
            'is_free' => $this->is_free,
            'price' => $this->price(),
            'enrollment_count' => $this->enrollment_count,
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            'featured_position' => $this->featured_position,
            'published_at' => $this->published_at?->toIso8601String(),
            'taken_down_at' => $this->taken_down_at?->toIso8601String(),
            'takedown_reason' => $this->takedown_reason,
            'can' => $this->can(),
        ];
    }

    /**
     * @return array{amount_minor: int, currency: string}|null
     */
    private function price(): ?array
    {
        return $this->is_free || $this->price_minor === null || $this->currency === null
            ? null
            : Money::of($this->price_minor, Currency::from($this->currency))->toArray();
    }

    /**
     * @return array{unpublish: bool, republish: bool, take_down: bool, restore: bool}
     */
    private function can(): array
    {
        $takenDown = $this->taken_down_at !== null;
        $live = $this->live_version_id !== null;

        return [
            'unpublish' => ! $takenDown && $this->status === CourseStatus::Published,
            'republish' => ! $takenDown && $live && $this->status === CourseStatus::Unpublished,
            'take_down' => ! $takenDown && $live,
            'restore' => $takenDown,
        ];
    }
}
