<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Authoring\Actions\CourseLifecycle;
use App\Domain\Authoring\Actions\CourseReview;
use App\Domain\Authoring\Actions\CreateCourse;
use App\Domain\Authoring\Actions\StartDraftFromLive;
use App\Domain\Authoring\Actions\UpdateCourseSettings;
use App\Domain\Authoring\Models\Course;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Authoring\CreateCourseRequest;
use App\Http\Requests\Authoring\UpdateCourseSettingsRequest;
use App\Http\Resources\Authoring\CourseVersionResource;
use App\Http\Resources\Authoring\StudioCourseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The professor's courses (PRD-03): create, settings, lifecycle, review submission.
 */
final class CourseController extends Controller
{
    /**
     * My courses, newest first. Filter by `filter[status]`; sort by `created_at` or `published_at`.
     */
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[status]', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $courses = QueryBuilder::for(Course::query()
            ->where('professor_profile_id', $this->professor($request)->id)
            ->with('category', 'liveVersion', 'draftVersion'))
            ->allowedFilters(AllowedFilter::exact('status'))
            ->allowedSorts('created_at', 'published_at')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return StudioCourseResource::collection($courses);
    }

    public function store(CreateCourseRequest $request, CreateCourse $create): JsonResponse
    {
        /** @var array{title: string, language?: string, category_id?: string|null} $data */
        $data = $request->validated();
        $course = $create->handle($this->professor($request), $data);

        return (new StudioCourseResource($this->load($course)))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $course): StudioCourseResource
    {
        return new StudioCourseResource($this->load($this->course($request, $course)));
    }

    /**
     * Category, language, level, audience, price / free. Applies immediately (no review).
     */
    public function update(UpdateCourseSettingsRequest $request, string $course, UpdateCourseSettings $update): StudioCourseResource
    {
        return new StudioCourseResource($this->load($update->handle($this->course($request, $course), $request->validated())));
    }

    /**
     * Delete a never-published course (published courses are archived instead).
     */
    public function destroy(Request $request, string $course, CourseLifecycle $lifecycle): Response
    {
        $lifecycle->delete($this->course($request, $course));

        return response()->noContent();
    }

    /**
     * Start editing a published course: copies the live version into a new draft.
     */
    public function startDraft(Request $request, string $course, StartDraftFromLive $start): JsonResponse
    {
        $draft = $start->handle($this->course($request, $course));

        return (new CourseVersionResource($draft->load('sections.lessons.mediaAsset', 'sections.lessons.quiz.questions.options', 'sections.lessons.media')))
            ->response()->setStatusCode(201);
    }

    public function submit(Request $request, string $course, CourseReview $review): CourseVersionResource
    {
        $version = $review->submit($this->course($request, $course));

        return new CourseVersionResource($version->load('sections.lessons.mediaAsset', 'sections.lessons.quiz.questions.options', 'sections.lessons.media'));
    }

    public function withdraw(Request $request, string $course, CourseReview $review): CourseVersionResource
    {
        $version = $review->withdraw($this->course($request, $course));

        return new CourseVersionResource($version->load('sections.lessons.mediaAsset', 'sections.lessons.quiz.questions.options', 'sections.lessons.media'));
    }

    public function unpublish(Request $request, string $course, CourseLifecycle $lifecycle): StudioCourseResource
    {
        return new StudioCourseResource($this->load($lifecycle->unpublish($this->course($request, $course))));
    }

    public function republish(Request $request, string $course, CourseLifecycle $lifecycle): StudioCourseResource
    {
        return new StudioCourseResource($this->load($lifecycle->republish($this->course($request, $course))));
    }

    public function archive(Request $request, string $course, CourseLifecycle $lifecycle): StudioCourseResource
    {
        return new StudioCourseResource($this->load($lifecycle->archive($this->course($request, $course))));
    }

    private function course(Request $request, string $id): Course
    {
        return Course::query()->where('professor_profile_id', $this->professor($request)->id)->findOrFail($id);
    }

    private function load(Course $course): Course
    {
        return $course->load('category', 'liveVersion', 'draftVersion');
    }

    private function professor(Request $request): ProfessorProfile
    {
        /** @var ProfessorProfile */
        return $request->attributes->get('professor_profile');
    }
}
