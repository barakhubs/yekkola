<?php

declare(strict_types=1);

namespace App\Http\Resources\Learning;

use App\Domain\Learning\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of my certificates (PRD-06 FR-10), with its shareable verification link.
 *
 * @mixin Certificate
 */
final class CertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'serial' => $this->serial,
            'course' => [
                'id' => $this->course_id,
                'slug' => $this->relationLoaded('course') ? $this->course?->slug : null,
                'title' => $this->course_title_snapshot,
            ],
            'student_name' => $this->student_name_snapshot,
            'professor_name' => $this->professor_name_snapshot,
            'issued_at' => $this->issued_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'verification_url' => $this->verificationUrl(),
        ];
    }
}
