<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Authoring\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved course (PRD-04 FR-08). Composite key (user_id, course_id): write through the query builder.
 *
 * @property string $user_id
 * @property string $course_id
 * @property \Illuminate\Support\Carbon $created_at
 */
final class WishlistItem extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    /**
     * Listed courses a user saved, newest first.
     *
     * @return Builder<Course>
     */
    public static function coursesFor(string $userId): Builder
    {
        return Course::query()
            ->listed()
            ->join('wishlist_items as w', 'w.course_id', '=', 'courses.id')
            ->where('w.user_id', $userId)
            ->select('courses.*')
            ->orderByDesc('w.created_at')
            ->orderBy('courses.id');
    }
}
