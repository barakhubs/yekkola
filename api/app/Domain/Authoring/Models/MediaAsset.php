<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Models;

use App\Domain\Authoring\Enums\MediaAssetStatus;
use App\Domain\Authoring\Enums\MediaKind;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Integrations\Video\Rendition;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lesson's video or audio file: the uploaded source and its encrypted HLS output in R2 (arch §1.3). Owned by
 * the professor; reused across versions (a new draft doesn't re-upload). The AES-128 key lives here, encrypted,
 * never next to the segments.
 *
 * @property string $id
 * @property string $professor_profile_id
 * @property MediaKind $kind
 * @property string $provider
 * @property string|null $provider_upload_id
 * @property string|null $source_key
 * @property int|null $size_bytes
 * @property string|null $output_prefix
 * @property list<array{name: string, bandwidth: int, size_bytes: int, width: int|null, height: int|null}>|null $renditions
 * @property string|null $encryption_key base64 of the raw key
 * @property bool $has_poster
 * @property MediaAssetStatus $status
 * @property int|null $duration_seconds
 * @property string|null $error
 */
final class MediaAsset extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['encryption_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'status' => MediaAssetStatus::class,
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
            'renditions' => 'array',
            'encryption_key' => 'encrypted',
            'has_poster' => 'boolean',
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

    /**
     * @return list<Rendition> highest first
     */
    public function renditionList(): array
    {
        return array_map(Rendition::fromArray(...), $this->renditions ?? []);
    }

    public function rendition(string $name): ?Rendition
    {
        foreach ($this->renditionList() as $rendition) {
            if ($rendition->name === $name) {
                return $rendition;
            }
        }

        return null;
    }

    /** The raw 16-byte AES-128 key, or null before processing. */
    public function key(): ?string
    {
        $decoded = $this->encryption_key === null ? false : base64_decode($this->encryption_key, true);

        return $decoded === false ? null : $decoded;
    }
}
