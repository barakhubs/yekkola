<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learning;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\PostThread;
use App\Domain\Learning\Actions\ReplyToThread;
use App\Domain\Learning\CourseAccess;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\QaAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\ThreadReplyRequest;
use App\Http\Requests\Learning\ThreadRequest;
use App\Http\Resources\Learning\ThreadResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lesson Q&A (PRD-06 FR-13) for enrolled students and the course's professor: questions per lesson (latest
 * activity first), one question with its replies, ask, reply.
 */
final class ThreadController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    public function index(Request $request, string $lesson, CourseAccess $courses, QaAccess $qa): AnonymousResourceCollection
    {
        $user = $this->user($request);
        [$model, $course] = $courses->liveLesson($lesson);
        $qa->assertParticipant($user, $course);
        $professorUserId = $course->loadMissing('professor')->professor?->user_id;

        $threads = LessonThread::query()->with('author')->visibleTo($user)
            ->withCount(['replies as visible_reply_count' => fn ($q) => $q->visibleTo($user)])
            ->where('course_id', $course->id)->where('lesson_uid', $model->uid)
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate($this->perPage($request))->appends($request->query());

        $collection = ThreadResource::collection($threads);
        $collection->collection->each(fn (ThreadResource $r) => $r->withProfessor($professorUserId));

        return $collection;
    }

    public function store(ThreadRequest $request, string $lesson, PostThread $post): ThreadResource
    {
        $thread = $post->handle($this->user($request), $lesson, (string) $request->validated('title'), (string) $request->validated('body'));

        return $this->present($request, $thread);
    }

    public function show(Request $request, string $thread, QaAccess $qa): ThreadResource
    {
        $user = $this->user($request);
        $model = LessonThread::query()->visibleTo($user)->findOrFail($thread);
        $qa->assertParticipant($user, $model->course ?? abort(404));

        return $this->present($request, $model);
    }

    public function reply(ThreadReplyRequest $request, string $thread, ReplyToThread $reply): ThreadResource
    {
        $user = $this->user($request);
        $model = LessonThread::query()->visibleTo($user)->findOrFail($thread);
        $reply->handle($user, $model, (string) $request->validated('body'));

        return $this->present($request, $model->refresh());
    }

    private function present(Request $request, LessonThread $thread): ThreadResource
    {
        $user = $this->user($request);
        $thread->load(['author', 'course.professor', 'replies' => fn ($q) => $q->with('author')->visibleTo($user)->orderBy('created_at')->orderBy('id')]);

        return (new ThreadResource($thread))->withProfessor($thread->course?->professor?->user_id);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
