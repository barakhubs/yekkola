<?php

declare(strict_types=1);

namespace App\Http\Resources\Learning;

use App\Domain\Learning\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What anyone with the serial may check (PRD-06 FR-11, PRD-04 FR-09): who earned it, for which course,
 * and whether it is still valid. `course_slug` only while the course is listed.
 *
 * @mixin Certificate
 */
final class CertificateVerificationResource extends JsonResource
{
    public function __construct(Certificate $certificate, private readonly ?string $listedSlug)
    {
        parent::__construct($certificate);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'serial' => $this->serial,
            'status' => $this->isRevoked() ? 'revoked' : 'valid',
            'student_name' => $this->student_name_snapshot,
            'course_title' => $this->course_title_snapshot,
            'professor_name' => $this->professor_name_snapshot,
            'course_slug' => $this->listedSlug,
            'issued_at' => $this->issued_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
