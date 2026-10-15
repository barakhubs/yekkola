<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Actions\BrowseCourses;
use App\Domain\Catalog\Actions\SetFeaturedCourses;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\FeaturedCoursesRequest;
use App\Http\Resources\Catalog\CourseCardResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Featured courses on the home page, in admin order (PRD-04 BR-03, PRD-09).
 */
final class FeaturedCourseController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CourseCardResource::collection($this->featured());
    }

    /**
     * Replaces the featured list; the order given is the display order.
     */
    public function update(FeaturedCoursesRequest $request, SetFeaturedCourses $setFeatured): AnonymousResourceCollection
    {
        /** @var User $staff */
        $staff = $request->user();
        $setFeatured->handle($request->courseIds(), $staff);

        return CourseCardResource::collection($this->featured());
    }

    /**
     * @return Collection<int, Course>
     */
    private function featured(): Collection
    {
        return Course::query()->whereNotNull('featured_position')->orderBy('featured_position')
            ->with(BrowseCourses::cardRelations())->get();
    }
}
