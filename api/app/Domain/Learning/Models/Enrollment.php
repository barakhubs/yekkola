<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Authoring\Models\Course;
use App\Domain\Learning\Enums\EnrollmentSource;
use App\Domain\Learning\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The access grant (PRD-06). Access checks look only at active enrollments. One per user and course.
 *
 * @property string $id
 * @property string $user_id
 * @property string $course_id
 * @property EnrollmentSource $source
 * @property string|null $order_item_id
 * @property EnrollmentStatus $status
 * @property string|null $revoked_reason
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon $enrolled_at
 * @property int $progress_pct
 * @property string|null $last_lesson_uid
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
final class Enrollment extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['progress_pct' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => EnrollmentSource::class,
            'status' => EnrollmentStatus::class,
            'revoked_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'progress_pct' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', EnrollmentStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === EnrollmentStatus::Active;
    }
}
