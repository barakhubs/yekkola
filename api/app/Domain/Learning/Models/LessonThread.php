<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\ThreadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's question on a lesson (PRD-06 FR-13), keyed by the lesson uid so it survives new versions. Staff can
 * hide it (PRD-09 FR-10); a hidden question stays visible to its author only.
 *
 * @property string $id
 * @property string $course_id
 * @property string $lesson_uid
 * @property string $author_id
 * @property string $title
 * @property string $body
 * @property ThreadStatus $status
 * @property int $reply_count
 * @property \Illuminate\Support\Carbon|null $last_reply_at
 * @property \Illuminate\Support\Carbon|null $answered_at
 * @property \Illuminate\Support\Carbon|null $hidden_at
 * @property string|null $hidden_by
 * @property string|null $hidden_reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property int|null $visible_reply_count Set by `withCount` on lists.
 */
final class LessonThread extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'open', 'reply_count' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ThreadStatus::class,
            'reply_count' => 'integer',
            'last_reply_at' => 'datetime',
            'answered_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    /**
     * @return HasMany<ThreadReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ThreadReply::class);
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /**
     * What participants see: not hidden (except to its author) and not by a banned account (BR-03).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('lesson_threads.hidden_at')->orWhere('lesson_threads.author_id', $viewer->id))
            ->whereHas('author', fn (Builder $q) => $q->where('status', '!=', UserStatus::Banned));
    }
}
