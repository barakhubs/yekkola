<?php

declare(strict_types=1);

namespace App\Domain\Protection\Models;

use App\Domain\Protection\Enums\DocumentStampStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A student's stamped copy of a document lesson (PRD-07 FR-12), cached on the private disk. Keyed by the
 * source PDF, so a replaced document gets fresh copies.
 *
 * @property string $id
 * @property string $user_id
 * @property int $source_media_id
 * @property DocumentStampStatus $status
 * @property string|null $path
 * @property string|null $error
 */
final class DocumentStamp extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStampStatus::class,
            'source_media_id' => 'integer',
        ];
    }

    public function isReady(): bool
    {
        return $this->status === DocumentStampStatus::Ready && $this->path !== null;
    }
}
