<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Professors\Models\KycSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An identity check. File links are only included for staff (`forStaff()`); they point at the
 * audited streaming endpoint, never at storage.
 *
 * @mixin KycSubmission
 */
final class KycSubmissionResource extends JsonResource
{
    private bool $forStaff = false;

    public function forStaff(): self
    {
        $this->forStaff = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'document_type' => $this->document_type->value,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->created_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];

        if (! $this->forStaff) {
            return $data;
        }

        return $data + [
            'professor' => [
                'id' => $this->profile->id,
                'display_name' => $this->profile->display_name,
                'slug' => $this->profile->slug,
            ],
            'files' => collect(KycSubmission::FILES)
                ->filter(fn (string $kind) => $this->getFirstMedia($kind) !== null)
                ->mapWithKeys(fn (string $kind) => [$kind => route('admin.kyc-submissions.file', [$this->id, $kind])])
                ->all(),
        ];
    }
}
