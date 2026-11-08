<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\Learning\ThreadResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Q&A inbox (PRD-06 FR-13): questions on my courses, unanswered first by default, oldest first so none waits too
 * long. Hidden questions and those by banned accounts are left out. Answering goes through `POST /threads/{id}/replies`.
 */
final class ThreadController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[status]', description: '`open` (default), `answered` or `all`.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[course_id]', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $professor = ProfessorProfile::query()->where('user_id', $user->id)->firstOrFail();
        $filter = $request->query('filter');
        $status = is_array($filter) && is_string($filter['status'] ?? null) ? $filter['status'] : 'open';

        $threads = QueryBuilder::for(LessonThread::query()->with('author', 'course.liveVersion')->visibleTo($user)
            ->whereNull('hidden_at')
            ->whereIn('course_id', Course::query()->withTrashed()->where('professor_profile_id', $professor->id)->select('id'))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status)))
            ->allowedFilters(
                AllowedFilter::callback('status', fn () => null), // applied above, with its default
                AllowedFilter::exact('course_id'),
            )
            ->allowedSorts('created_at', 'updated_at')
            ->defaultSort('created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        // Lesson titles from each course's live version, in one query.
        $titles = Lesson::query()
            ->whereIn('course_version_id', collect($threads->items())->map(fn (LessonThread $t) => $t->course?->live_version_id)->filter()->unique()->values())
            ->whereIn('uid', collect($threads->items())->pluck('lesson_uid')->unique()->values())
            ->get(['course_version_id', 'uid', 'title'])
            ->mapWithKeys(fn (Lesson $l) => [$l->course_version_id.':'.$l->uid => $l->title]);

        $collection = ThreadResource::collection($threads);
        $collection->collection->each(fn (ThreadResource $r) => $r
            ->withProfessor($user->id)
            ->withLessonTitle((string) ($titles->get($r->resource->course?->live_version_id.':'.$r->resource->lesson_uid) ?? '')));

        return $collection;
    }
}
