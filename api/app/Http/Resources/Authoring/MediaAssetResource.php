<?php

declare(strict_types=1);

namespace App\Http\Resources\Authoring;

use App\Domain\Authoring\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Processing status of an uploaded video/audio file. No playback ids — playback goes through tokens.
 *
 * @mixin MediaAsset
 */
final class MediaAssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'duration_seconds' => $this->duration_seconds,
            'error' => $this->error,
        ];
    }
}
