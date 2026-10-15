<?php

declare(strict_types=1);

use App\Domain\Authoring\Actions\CourseReview;
use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Professors\Models\ProfessorProfile;

/*
| Catalogue helpers (PRD-04).
*/

/**
 * A listed course: built, submitted, and approved by a staff member. `$settings` overrides course columns
 * (category, price, language…) after publishing. Requires Storage::fake('local') and ('public').
 *
 * @param  array<string, mixed>  $settings
 */
function publishedCourse(ProfessorProfile $professor, string $title, array $settings = []): Course
{
    $course = courseReadyForReview($professor, $title);
    $review = app(CourseReview::class);
    $review->submit($course);
    $review->approve($course->refresh()->draftVersion, User::factory()->create(), null);

    $course->refresh()->update($settings);

    return $course->refresh();
}
