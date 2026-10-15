<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Actions\BrowseCourses;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicProfessorResource;

final class ProfessorController extends Controller
{
    /**
     * A professor's public page with their listed courses (PRD-02 FR-12, PRD-04 FR-07).
     */
    public function show(string $slug): PublicProfessorResource
    {
        $profile = ProfessorProfile::query()->public()->with('province')->where('slug', $slug)->firstOrFail();

        $courses = Course::query()->listed()->where('professor_profile_id', $profile->id)
            ->with(BrowseCourses::cardRelations())
            ->orderByDesc('enrollment_count')->orderByDesc('published_at')
            ->get();

        return (new PublicProfessorResource($profile))->withCourses($courses);
    }
}
