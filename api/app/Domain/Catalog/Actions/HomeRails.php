<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Authoring\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Home page rails (PRD-04 FR-01): featured (admin order, BR-03), popular, new, and free. Simple rules,
 * no location-based ranking (BR-04). Courses by category come from the catalogue endpoint.
 */
final class HomeRails
{
    /**
     * @return array{featured: Collection<int, Course>, popular: Collection<int, Course>, new: Collection<int, Course>, free: Collection<int, Course>}
     */
    public function handle(): array
    {
        return [
            'featured' => $this->rail(fn (Builder $q) => $q->whereNotNull('featured_position')->orderBy('featured_position')),
            // TODO(1.8): rank by enrollments in the last 30 days once enrollments exist.
            'popular' => $this->rail(fn (Builder $q) => $q->orderByDesc('enrollment_count')->orderByDesc('published_at')),
            'new' => $this->rail(fn (Builder $q) => $q->orderByDesc('published_at')),
            'free' => $this->rail(fn (Builder $q) => $q->where('is_free', true)->orderByDesc('enrollment_count')->orderByDesc('published_at')),
        ];
    }

    /**
     * @param  callable(Builder<Course>): mixed  $scope
     * @return Collection<int, Course>
     */
    private function rail(callable $scope): Collection
    {
        $query = Course::query()->listed()->with(BrowseCourses::cardRelations());
        $scope($query);

        return $query->limit((int) config('yekkola.catalogue.rail_size'))->get();
    }
}
