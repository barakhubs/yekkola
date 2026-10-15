<?php

declare(strict_types=1);

namespace App\Domain\Professors\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Professors\Enums\KycDocumentType;
use App\Domain\Professors\Enums\KycSubmissionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One identity check (PRD-02 FR-08): ID front, back (not for passports), and a selfie, on the private disk.
 * Files are only streamed to staff with the KYC permission, and each view is audited (BR-04).
 *
 * @property string $id
 * @property string $professor_profile_id
 * @property KycDocumentType $document_type
 * @property KycSubmissionStatus $status
 * @property string|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon $created_at
 */
final class KycSubmission extends Model implements HasMedia
{
    use HasUlids;
    use InteractsWithMedia;

    /** Media collections, one file each. */
    public const FILES = ['front', 'back', 'selfie'];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => KycDocumentType::class,
            'status' => KycSubmissionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        foreach (self::FILES as $collection) {
            $this->addMediaCollection($collection)
                ->singleFile()
                ->useDisk((string) config('yekkola.private_disk'))
                ->acceptsMimeTypes(['image/jpeg', 'image/png', 'application/pdf']);
        }
    }

    /**
     * @return BelongsTo<ProfessorProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProfessorProfile::class, 'professor_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
