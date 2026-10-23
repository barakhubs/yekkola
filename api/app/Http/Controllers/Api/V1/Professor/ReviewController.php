<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\ReplyToReview;
use App\Domain\Learning\Models\Review;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\ReviewReplyRequest;
use App\Http\Resources\Learning\ReviewResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/** Reviews of my courses and my public replies (PRD-06 FR-12, US-9). */
final class ReviewController extends Controller
{
    /**
     * Newest first. `filter[course_id]`, `filter[rating]`, `filter[replied]=true|false`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $reviews = QueryBuilder::for($this->own($request)->with('user', 'course'))
            ->allowedFilters(
                AllowedFilter::exact('course_id'),
                AllowedFilter::exact('rating'),
                AllowedFilter::callback('replied', fn ($q, $value) => filter_var($value, FILTER_VALIDATE_BOOLEAN)
                    ? $q->whereNotNull('professor_reply')
                    : $q->whereNull('professor_reply')),
            )
            ->allowedSorts('created_at', 'rating')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        $collection = ReviewResource::collection($reviews);
        $collection->collection->each(fn (ReviewResource $resource) => $resource->private());

        return $collection;
    }

    /**
     * Sets, edits, or (with `reply: null`) removes my public reply.
     */
    public function reply(ReviewReplyRequest $request, string $review, ReplyToReview $reply): ReviewResource
    {
        $model = $this->own($request)->with('user', 'course')->findOrFail($review);

        return (new ReviewResource($reply->handle($model, $request->validated('reply'))))->private();
    }

    /**
     * @return Builder<Review>
     */
    private function own(Request $request): Builder
    {
        return Review::query()->whereIn('course_id', Course::query()->withTrashed()->where('professor_profile_id', $this->professor($request)->id)->select('id'));
    }

    private function professor(Request $request): ProfessorProfile
    {
        /** @var ProfessorProfile */
        return $request->attributes->get('professor_profile') ?? ProfessorProfile::query()->where('user_id', $this->user($request)->id)->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
