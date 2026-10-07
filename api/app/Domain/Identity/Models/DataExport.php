<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\DataExportStatus;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A user's personal-data export (PRD-01 FR-12). The file lives on the private disk (`yekkola.private_disk`:
 * local in development, object storage in staging/production so every instance can serve it) and is
 * pruned with its row a day after it expires.
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
    use Prunable;

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

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('yekkola.private_disk'));
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

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where(fn (Builder $q) => $q
            ->where('expires_at', '<', now()->subDay())
            ->orWhere(fn (Builder $q) => $q->where('status', DataExportStatus::Failed)->where('created_at', '<', now()->subDay())));
    }

    protected function pruning(): void
    {
        if ($this->path !== null) {
            self::disk()->delete($this->path);
        }
    }
}
