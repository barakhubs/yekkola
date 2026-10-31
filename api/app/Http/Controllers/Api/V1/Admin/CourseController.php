<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Authoring\Actions\ChangeCourseAvailability;
use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffNoteRequest;
use App\Http\Resources\Admin\AdminCourseResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Courses in the back office (PRD-09 FR-08): find any course, unpublish or republish it, take it down or restore
 * it. A reason (`note`) is required and audited.
 */
final class CourseController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[q]', description: 'Part of the title or slug.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[status]', description: 'draft, in_review, published, unpublished or archived.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[taken_down]', description: '`1`: only taken-down courses.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[professor_profile_id]', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('sort', description: '`created_at`, `published_at` or `enrollment_count`; prefix `-` for descending (default `-created_at`).', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $courses = QueryBuilder::for(Course::query()->with('liveVersion', 'draftVersion', 'professor', 'category'))
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('professor_profile_id'),
                AllowedFilter::callback('taken_down', fn (Builder $q, $on) => $on ? $q->whereNotNull('taken_down_at') : $q),
                AllowedFilter::callback('q', fn (Builder $q, $term) => $q->where(fn (Builder $w) => $w
                    ->where('slug', 'ilike', '%'.addcslashes((string) $term, '%_\\').'%')
                    ->orWhereHas('liveVersion', fn (Builder $v) => $v->where('title', 'ilike', '%'.addcslashes((string) $term, '%_\\').'%'))
                    ->orWhereHas('draftVersion', fn (Builder $v) => $v->where('title', 'ilike', '%'.addcslashes((string) $term, '%_\\').'%')))),
            )
            ->allowedSorts('created_at', 'published_at', 'enrollment_count')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return AdminCourseResource::collection($courses);
    }

    /** Off the catalogue for new students; enrolled students keep access. */
    public function unpublish(StaffNoteRequest $request, Course $course, ChangeCourseAvailability $change): AdminCourseResource
    {
        return $this->present($change->unpublish($course, $this->staff($request), (string) $request->note()));
    }

    public function republish(StaffNoteRequest $request, Course $course, ChangeCourseAvailability $change): AdminCourseResource
    {
        return $this->present($change->republish($course, $this->staff($request), (string) $request->note()));
    }

    /** Off the catalogue and closed to everyone, enrolled students included (no new playback or documents). */
    public function takeDown(StaffNoteRequest $request, Course $course, ChangeCourseAvailability $change): AdminCourseResource
    {
        return $this->present($change->takeDown($course, $this->staff($request), (string) $request->note()));
    }

    public function restore(StaffNoteRequest $request, Course $course, ChangeCourseAvailability $change): AdminCourseResource
    {
        return $this->present($change->restore($course, $this->staff($request), (string) $request->note()));
    }

    private function present(Course $course): AdminCourseResource
    {
        return new AdminCourseResource($course->load('liveVersion', 'draftVersion', 'professor', 'category'));
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
