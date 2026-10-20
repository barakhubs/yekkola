<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Enums\ProgressStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a student is in a lesson, keyed by the lesson's stable uid (PRD-06 BR-02). Written only through
 * SyncProgress's monotonic upsert.
 *
 * @property string $id
 * @property string $user_id
 * @property string $course_id
 * @property string $lesson_uid
 * @property ProgressStatus $status
 * @property int $position_seconds
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $client_updated_at
 * @property string|null $device_id
 */
final class LessonProgress extends Model
{
    use HasUlids;

    protected $table = 'lesson_progress';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProgressStatus::class,
            'position_seconds' => 'integer',
            'completed_at' => 'datetime',
            'client_updated_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === ProgressStatus::Completed;
    }
}
