<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Actions\BrowseCourses;
use App\Domain\Catalog\Actions\HomeRails;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BrowseCoursesRequest;
use App\Http\Resources\Catalog\CourseCardResource;
use App\Http\Resources\Catalog\CourseDetailResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public catalogue (PRD-04): browse and search, course pages, and home rails. Only listed courses
 * (published, active professor); anything else is a 404 (FR-12).
 */
final class CourseController extends Controller
{
    /**
     * Catalogue and search: `q`, `category` (slug, includes subcategories), `price` (free/paid), `currency`,
     * `language`, `level`, `audience`, `sort` (relevance, popular, newest, rating, price_asc, price_desc).
     */
    public function index(BrowseCoursesRequest $request, BrowseCourses $browse): AnonymousResourceCollection
    {
        $courses = $browse->handle($request->filters(), $this->perPage($request))->appends($request->query());

        return CourseCardResource::collection($courses);
    }

    public function show(string $slug): CourseDetailResource
    {
        $course = Course::query()
            ->listed()
            ->where('slug', $slug)
            ->with([
                ...BrowseCourses::cardRelations(),
                'professor.province',
                'category.parent',
                'liveVersion.sections.lessons.media',
            ])
            ->firstOrFail();

        return new CourseDetailResource($course);
    }

    /**
     * Home page rails: featured (admin order), popular, new, free.
     *
     * @return array{data: array<string, AnonymousResourceCollection>}
     */
    public function featured(HomeRails $rails): array
    {
        return ['data' => array_map(fn ($courses) => CourseCardResource::collection($courses), $rails->handle())];
    }
}
