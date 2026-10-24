<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learning;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\DeleteReview;
use App\Domain\Learning\Actions\PostReview;
use App\Domain\Learning\Models\Review;
use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\ReviewRequest;
use App\Http\Resources\Learning\ReviewResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/** Course reviews (PRD-06 FR-12): the public list, and my own review of a course. */
final class ReviewController extends Controller
{
    /**
     * Published reviews of a listed course, newest first. `filter[rating]=1..5`, `sort=-created_at|-rating|rating`.
     */
    #[QueryParameter('page', type: 'int', default: 1)]
    #[QueryParameter('per_page', type: 'int', default: 15)]
    #[QueryParameter('filter[rating]', description: 'Only reviews with this rating (1–5).', type: 'int')]
    #[QueryParameter('sort', description: '`-created_at` (default), `-rating`, `rating`, `created_at`.', type: 'string')]
    public function index(Request $request, string $slug): AnonymousResourceCollection
    {
        $course = Course::query()->listed()->where('slug', $slug)->firstOrFail();

        $reviews = QueryBuilder::for(Review::query()->published()->where('reviews.course_id', $course->id)->with('user'))
            ->allowedFilters(AllowedFilter::exact('rating'))
            ->allowedSorts('created_at', 'rating')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return ReviewResource::collection($reviews);
    }

    public function show(Request $request, string $course): ReviewResource
    {
        return (new ReviewResource($this->mine($request, $course)->with('user')->firstOrFail()))->private();
    }

    /**
     * Posts my review: enrolled, past the progress threshold (setting), not my own course, one per course.
     */
    public function store(ReviewRequest $request, string $course, PostReview $post): JsonResponse
    {
        $review = $post->handle($this->user($request), $course, $request->integer('rating'), $request->validated('body'), creating: true);

        return (new ReviewResource($review->load('user')))->private()->response()->setStatusCode(201);
    }

    public function update(ReviewRequest $request, string $course, PostReview $post): ReviewResource
    {
        $review = $post->handle($this->user($request), $course, $request->integer('rating'), $request->validated('body'), creating: false);

        return (new ReviewResource($review->load('user')))->private();
    }

    public function destroy(Request $request, string $course, DeleteReview $delete): Response
    {
        $delete->handle($this->mine($request, $course)->firstOrFail());

        return response()->noContent();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Review>
     */
    private function mine(Request $request, string $course)
    {
        return Review::query()->where('user_id', $this->user($request)->id)->where('course_id', $course);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
