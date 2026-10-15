<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Actions\BrowseCourses;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;

/**
 * A student's saved courses (PRD-04 FR-08). Adding is idempotent and only for listed courses; the list
 * shows only courses still listed, newest saved first.
 */
final class Wishlist
{
    private const TABLE = 'wishlist_items';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function add(User $user, Course $course): void
    {
        $this->db->table(self::TABLE)->insertOrIgnore([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'created_at' => now(),
        ]);
    }

    public function remove(User $user, string $courseId): void
    {
        $this->db->table(self::TABLE)->where('user_id', $user->id)->where('course_id', $courseId)->delete();
    }

    /**
     * @return LengthAwarePaginator<int, Course>
     */
    public function list(User $user, int $perPage): LengthAwarePaginator
    {
        return Course::query()
            ->listed()
            ->join(self::TABLE.' as w', 'w.course_id', '=', 'courses.id')
            ->where('w.user_id', $user->id)
            ->select('courses.*')
            ->with(BrowseCourses::cardRelations())
            ->orderByDesc('w.created_at')
            ->orderBy('courses.id')
            ->paginate($perPage);
    }

    /**
     * @return list<string>
     */
    public function slugs(User $user): array
    {
        return Course::query()
            ->join(self::TABLE.' as w', 'w.course_id', '=', 'courses.id')
            ->where('w.user_id', $user->id)
            ->orderBy('w.created_at')
            ->pluck('courses.slug')
            ->all();
    }

    public function clear(User $user): void
    {
        $this->db->table(self::TABLE)->where('user_id', $user->id)->delete();
    }
}
