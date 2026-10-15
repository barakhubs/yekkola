<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Models;

use App\Domain\Authoring\Enums\MediaAssetStatus;
use App\Domain\Authoring\Enums\MediaKind;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A video or audio file on the video provider (Mux). Owned by the professor; reused across versions
 * (a new draft doesn't re-upload).
 *
 * @property string $id
 * @property string $professor_profile_id
 * @property MediaKind $kind
 * @property string $provider
 * @property string|null $provider_upload_id
 * @property string|null $provider_asset_id
 * @property string|null $playback_id
 * @property MediaAssetStatus $status
 * @property int|null $duration_seconds
 * @property string|null $error
 */
final class MediaAsset extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'status' => MediaAssetStatus::class,
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProfessorProfile, $this>
     */
    public function professor(): BelongsTo
    {
        return $this->belongsTo(ProfessorProfile::class, 'professor_profile_id');
    }

    public function isReady(): bool
    {
        return $this->status === MediaAssetStatus::Ready;
    }
}
