<?php

declare(strict_types=1);

namespace App\Http\Resources\Learning;

use App\Domain\Learning\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Enrollment
 */
final class EnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'source' => $this->source->value,
            'status' => $this->status->value,
            'enrolled_at' => $this->enrolled_at->toIso8601String(),
        ];
    }
}
