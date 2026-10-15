<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Authoring\Models\Course;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Database\ConnectionInterface;

/**
 * Creates a course with its first draft version (PRD-03 FR-01).
 */
final class CreateCourse
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CourseSlugs $slugs,
    ) {}

    /**
     * @param  array{title: string, language?: string, category_id?: string|null}  $data
     */
    public function handle(ProfessorProfile $professor, array $data): Course
    {
        return $this->db->transaction(function () use ($professor, $data): Course {
            $course = Course::query()->create([
                'professor_profile_id' => $professor->id,
                'slug' => $this->slugs->unique($data['title']),
                'status' => CourseStatus::Draft,
                'language' => $data['language'] ?? 'fr',
                'category_id' => $data['category_id'] ?? null,
            ]);

            $version = $course->versions()->create([
                'number' => 1,
                'state' => VersionState::Draft,
                'title' => $data['title'],
            ]);

            $course->forceFill(['draft_version_id' => $version->id])->save();

            return $course;
        });
    }
}
