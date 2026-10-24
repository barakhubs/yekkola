<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Listeners;

use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Jobs\RevalidateWebPages;
use App\Domain\Professors\Models\ProfessorProfile;

/** Refreshes the web app's cached public pages when what they show changes (PRD-04 FR-11, FR-12). */
final class RevalidateWebOnChange
{
    /** Course columns the public pages show (ratings refresh on the normal cache lifetime). */
    private const COURSE_FIELDS = [
        'status', 'live_version_id', 'slug', 'category_id', 'language', 'level', 'audience',
        'is_free', 'price_minor', 'currency', 'featured_position', 'deleted_at',
    ];

    private const PROFESSOR_FIELDS = ['status', 'slug', 'display_name', 'headline', 'bio', 'province_id', 'kyc_status'];

    public function courseSaved(Course $course): void
    {
        if (! $course->wasChanged(self::COURSE_FIELDS)) {
            return;
        }
        $previous = $course->getPrevious();
        if ($course->live_version_id === null && ($previous['live_version_id'] ?? null) === null) {
            return; // never public
        }

        $slugs = array_unique(array_filter([$course->slug, $previous['slug'] ?? null]));
        RevalidateWebPages::dispatch(['courses', 'categories', ...array_map(fn ($s) => "course:{$s}", $slugs)]);
    }

    public function courseDeleted(Course $course): void
    {
        RevalidateWebPages::dispatch(['courses', 'categories', "course:{$course->slug}"]);
    }

    public function professorSaved(ProfessorProfile $professor): void
    {
        if (! $professor->wasChanged(self::PROFESSOR_FIELDS)) {
            return;
        }

        $slugs = array_unique(array_filter([$professor->slug, $professor->getPrevious()['slug'] ?? null]));
        // Course pages show the professor block too.
        RevalidateWebPages::dispatch(['courses', ...array_map(fn ($s) => "professor:{$s}", $slugs)]);
    }
}
