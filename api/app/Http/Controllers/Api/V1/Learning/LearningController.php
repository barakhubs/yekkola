<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learning;

use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Actions\BrowseCourses;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\SyncProgress;
use App\Domain\Learning\CourseAccess;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Learning\Models\LessonProgress;
use App\Domain\Learning\Models\QuizAttempt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\SyncProgressRequest;
use App\Http\Resources\Learning\LearningViewResource;
use App\Http\Resources\Learning\MyCourseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/** A student's courses, learning view, and progress sync (PRD-06 FR-01, FR-02, FR-05). */
final class LearningController extends Controller
{
    /**
     * My courses, most recently active first. `filter[state]=in_progress|completed`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $enrollments = QueryBuilder::for(Enrollment::query()->active()->where('user_id', $this->user($request)->id)
            ->with(['course' => fn ($q) => $q->with(BrowseCourses::cardRelations())]))
            ->allowedFilters(AllowedFilter::callback('state', fn ($q, $value) => $value === 'completed'
                ? $q->whereNotNull('completed_at')
                : $q->whereNull('completed_at')))
            ->allowedSorts('last_activity_at', 'enrolled_at')
            ->defaultSort('-last_activity_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return MyCourseResource::collection($enrollments);
    }

    public function show(Request $request, string $course, CourseAccess $access): LearningViewResource
    {
        $user = $this->user($request);
        $enrollment = $access->enrollment($user, $course);
        $model = Course::query()->whereKey($course)->whereNotNull('live_version_id')
            ->with('professor', 'liveVersion.media', 'liveVersion.sections.lessons.quiz.questions')
            ->firstOrFail();

        $uids = $model->liveVersion->sections->flatMap->lessons->pluck('uid');
        $progress = LessonProgress::query()->where('user_id', $user->id)->whereIn('lesson_uid', $uids)->get()->keyBy('lesson_uid');
        $attempts = QuizAttempt::query()->where('user_id', $user->id)->whereIn('lesson_uid', $uids)->get();

        return new LearningViewResource($model, $enrollment, $progress, $attempts);
    }

    /**
     * Batch progress sync (web every 15 s and on pause/leave; mobile when back online). Monotonic: an older
     * sync never undoes newer progress.
     *
     * @return array{data: array<string, mixed>}
     */
    public function sync(SyncProgressRequest $request, SyncProgress $sync): array
    {
        $user = $this->user($request);

        return ['data' => $sync->handle($user, $request->items(), $user->currentDeviceId())];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
