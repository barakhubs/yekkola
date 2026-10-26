<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Authoring\Actions\CourseReview;
use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffNoteRequest;
use App\Http\Resources\Admin\AdminCourseVersionDetailResource;
use App\Http\Resources\Admin\AdminCourseVersionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Course review queue (PRD-09 FR-07): versions in review, oldest first; approve or reject with notes.
 * Drafts are never visible here.
 */
final class CourseVersionController extends Controller
{
    /**
     * Review queue (PRD-09 FR-07): versions submitted or decided, oldest submissions first by default.
     */
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[state]', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('sort', description: '`submitted_at` or `reviewed_at`; prefix `-` for newest first.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $versions = QueryBuilder::for(CourseVersion::query()
            ->where('state', '!=', VersionState::Draft)
            ->whereHas('course')
            ->with('course.professor', 'course.category', 'course.liveVersion', 'course.draftVersion'))
            ->allowedFilters(AllowedFilter::exact('state'))
            ->allowedSorts('submitted_at', 'reviewed_at')
            ->defaultSort('submitted_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return AdminCourseVersionResource::collection($versions);
    }

    public function show(CourseVersion $version): AdminCourseVersionDetailResource
    {
        abort_if($version->state === VersionState::Draft || $version->course === null, 404);

        return $this->present($version);
    }

    public function approve(StaffNoteRequest $request, CourseVersion $version, CourseReview $review): AdminCourseVersionDetailResource
    {
        return $this->present($review->approve($version, $this->staff($request), $request->note()));
    }

    public function reject(StaffNoteRequest $request, CourseVersion $version, CourseReview $review): AdminCourseVersionDetailResource
    {
        return $this->present($review->reject($version, $this->staff($request), (string) $request->note()));
    }

    private function present(CourseVersion $version): AdminCourseVersionDetailResource
    {
        return new AdminCourseVersionDetailResource($version->refresh()->load(
            'course.professor', 'course.category', 'course.liveVersion', 'course.draftVersion',
            'sections.lessons.mediaAsset', 'sections.lessons.quiz.questions.options', 'sections.lessons.media', 'lessons',
        ));
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
