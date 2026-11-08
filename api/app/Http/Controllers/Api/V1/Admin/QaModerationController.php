<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\ModerateQa;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\Models\ThreadReply;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffNoteRequest;
use App\Http\Resources\Admin\AdminThreadResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Lesson Q&A moderation (PRD-09 FR-10): every question with its replies, hidden ones too, newest first; hide or
 * restore a question or a reply with a reason.
 */
final class QaModerationController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[hidden]', description: '`true`: questions hidden or with a hidden reply; `false`: neither.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[course_id]', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[q]', description: 'Words in the question or a reply.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $hasHidden = fn (Builder $q) => $q->whereNotNull('hidden_at')->orWhereHas('replies', fn (Builder $r) => $r->whereNotNull('hidden_at'));

        $threads = QueryBuilder::for(LessonThread::query()->with(['author', 'course.liveVersion', 'replies' => fn ($q) => $q->with('author')->orderBy('created_at')]))
            ->allowedFilters(
                AllowedFilter::exact('course_id'),
                AllowedFilter::callback('hidden', fn (Builder $q, $value) => filter_var($value, FILTER_VALIDATE_BOOLEAN)
                    ? $q->where($hasHidden)
                    : $q->whereNot($hasHidden)),
                AllowedFilter::callback('q', function (Builder $q, $term): void {
                    $like = '%'.addcslashes((string) $term, '%_\\').'%';
                    $q->where(fn (Builder $w) => $w->where('title', 'ilike', $like)->orWhere('body', 'ilike', $like)
                        ->orWhereHas('replies', fn (Builder $r) => $r->where('body', 'ilike', $like)));
                }),
            )
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return AdminThreadResource::collection($threads);
    }

    public function hideThread(StaffNoteRequest $request, LessonThread $thread, ModerateQa $moderate): AdminThreadResource
    {
        $moderate->hide($thread, $this->staff($request), (string) $request->note());

        return $this->present($thread);
    }

    public function restoreThread(StaffNoteRequest $request, LessonThread $thread, ModerateQa $moderate): AdminThreadResource
    {
        $moderate->restore($thread, $this->staff($request), (string) $request->note());

        return $this->present($thread);
    }

    public function hideReply(StaffNoteRequest $request, ThreadReply $reply, ModerateQa $moderate): AdminThreadResource
    {
        $moderate->hide($reply, $this->staff($request), (string) $request->note());

        return $this->present($reply->thread()->firstOrFail());
    }

    public function restoreReply(StaffNoteRequest $request, ThreadReply $reply, ModerateQa $moderate): AdminThreadResource
    {
        $moderate->restore($reply, $this->staff($request), (string) $request->note());

        return $this->present($reply->thread()->firstOrFail());
    }

    private function present(LessonThread $thread): AdminThreadResource
    {
        return new AdminThreadResource($thread->refresh()->load(['author', 'course.liveVersion', 'replies' => fn ($q) => $q->with('author')->orderBy('created_at')]));
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
