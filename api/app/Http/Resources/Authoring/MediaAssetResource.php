<?php

declare(strict_types=1);

namespace App\Http\Resources\Authoring;

use App\Domain\Authoring\Enums\MediaAssetStatus;
use App\Domain\Authoring\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Processing status of an uploaded video/audio file. No storage keys or URLs — playback goes through the API's
 * playlists. `error` is a translated message for the professor (the technical reason stays in our logs).
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
            'size_bytes' => $this->size_bytes,
            'error' => $this->status === MediaAssetStatus::Errored ? __('errors.media.processing_failed') : null,
        ];
    }
}
