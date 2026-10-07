<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\DataExportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's personal-data export (PRD-01 FR-12). The file lives on the private disk and expires.
 *
 * @property string $id
 * @property string $user_id
 * @property DataExportStatus $status
 * @property string|null $path
 * @property \Illuminate\Support\Carbon|null $ready_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class DataExport extends Model
{
    use HasUlids;

    public const DISK = 'local';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DataExportStatus::class,
            'ready_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === DataExportStatus::Ready
            && $this->path !== null
            && $this->expires_at?->isFuture() === true;
    }
}
