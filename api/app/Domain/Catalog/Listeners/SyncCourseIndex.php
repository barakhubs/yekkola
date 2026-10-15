<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Listeners;

use App\Domain\Catalog\Jobs\ReindexCourses;
use App\Domain\Catalog\Models\Category;
use App\Domain\Identity\Models\User;
use App\Domain\Professors\Models\ProfessorProfile;

/**
 * Keeps the course index in step with rows it depends on (PRD-04 FR-11). Course rows themselves sync
 * through Scout's model observer; this covers professors, their accounts, and categories.
 */
final class SyncCourseIndex
{
    public function professorSaved(ProfessorProfile $profile): void
    {
        if ($profile->wasChanged(['status', 'display_name'])) {
            ReindexCourses::dispatch('professor_profile_id', $profile->id);
        }
    }

    public function userSaved(User $user): void
    {
        if ($user->wasChanged('status')) {
            $this->reindexProfessorOf($user);
        }
    }

    public function userDeleted(User $user): void
    {
        $this->reindexProfessorOf($user);
    }

    private function reindexProfessorOf(User $user): void
    {
        $profileId = ProfessorProfile::query()->where('user_id', $user->id)->value('id');
        if ($profileId !== null) {
            ReindexCourses::dispatch('professor_profile_id', (string) $profileId);
        }
    }

    public function categorySaved(Category $category): void
    {
        if (! $category->wasChanged(['name', 'parent_id'])) {
            return;
        }

        // Courses index their category's and its parent's names.
        $ids = [$category->id, ...Category::query()->where('parent_id', $category->id)->pluck('id')->all()];
        foreach ($ids as $id) {
            ReindexCourses::dispatch('category_id', (string) $id);
        }
    }
}
