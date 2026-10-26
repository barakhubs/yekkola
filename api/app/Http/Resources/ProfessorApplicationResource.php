<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Professors\Enums\ApplicationStatus;
use App\Domain\Professors\Models\ProfessorApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * An application as its applicant sees it. Staff views add the applicant (see `forStaff()`).
 *
 * @mixin ProfessorApplication
 */
final class ProfessorApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'full_name' => $this->full_name,
            'subjects' => $this->subjects,
            'levels' => $this->levels,
            'years_experience' => $this->years_experience,
            'institutions' => $this->institutions,
            'credentials' => $this->credentials,
            'sample_url' => $this->sample_url,
            'motivation' => $this->motivation,
            'province' => $this->whenLoaded('province', fn () => $this->province ? new ProvinceResource($this->province) : null),
            'credential_files' => $this->getMedia(ProfessorApplication::CREDENTIALS)->map(fn (Media $m) => [
                'id' => $m->uuid,
                'name' => $m->file_name,
                'mime_type' => $m->mime_type,
                'size' => $m->size,
            ])->values()->all(),
            'is_editable' => $this->status->isEditable(),
            'info_request_note' => $this->info_request_note,
            'decision_note' => $this->status === ApplicationStatus::Rejected ? $this->decision_note : null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
