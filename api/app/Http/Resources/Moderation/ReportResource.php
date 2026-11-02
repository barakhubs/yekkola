<?php

declare(strict_types=1);

namespace App\Http\Resources\Moderation;

use App\Domain\Moderation\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A report as its author sees it: received, nothing about who else reported or what staff decided.
 *
 * @mixin Report
 */
final class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->reportable_type->value,
            'target_id' => $this->reportable_id,
            'reason' => $this->reason->value,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
