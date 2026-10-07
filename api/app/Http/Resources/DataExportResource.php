<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Identity\Models\DataExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DataExport
 */
final class DataExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'requested_at' => $this->created_at->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'download_url' => $this->isDownloadable() ? route('me.export.download') : null,
        ];
    }
}
