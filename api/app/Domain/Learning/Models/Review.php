<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's rating and review of a course (PRD-06 FR-12), one per student and course, with the
 * professor's public reply.
 *
 * @property string $id
 * @property string $user_id
 * @property string $course_id
 * @property int $rating
 * @property string|null $body
 * @property ReviewStatus $status
 * @property string|null $professor_reply
 * @property \Illuminate\Support\Carbon|null $replied_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class Review extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'visible'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ReviewStatus::class,
            'replied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    /**
     * What the public sees and what the rating counts: visible reviews of authors who aren't banned (BR-03).
     *
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('reviews.status', ReviewStatus::Visible)
            ->whereIn('reviews.user_id', User::query()->where('status', '!=', UserStatus::Banned)->select('id'));
    }
}
