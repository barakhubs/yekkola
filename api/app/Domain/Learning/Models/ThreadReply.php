<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply to a lesson question (PRD-06 FR-13). `is_professor` marks the course's professor (highlighted).
 *
 * @property string $id
 * @property string $lesson_thread_id
 * @property string $author_id
 * @property string $body
 * @property bool $is_professor
 * @property \Illuminate\Support\Carbon|null $hidden_at
 * @property string|null $hidden_by
 * @property string|null $hidden_reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class ThreadReply extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['is_professor' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_professor' => 'boolean', 'hidden_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<LessonThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(LessonThread::class, 'lesson_thread_id');
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('thread_replies.hidden_at')->orWhere('thread_replies.author_id', $viewer->id))
            ->whereHas('author', fn (Builder $q) => $q->where('status', '!=', UserStatus::Banned));
    }
}
