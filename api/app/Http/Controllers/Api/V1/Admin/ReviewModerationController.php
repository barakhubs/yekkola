<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\ModerateReview;
use App\Domain\Learning\Models\Review;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffNoteRequest;
use App\Http\Resources\Admin\AdminReviewResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Review moderation (PRD-09 FR-10): every review (hidden ones too), newest first; hide or restore with a reason.
 */
final class ReviewModerationController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[status]', description: '`visible` or `hidden`.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[course_id]', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[rating]', description: '1 to 5.', type: 'int')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[q]', description: 'Words in the review.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('sort', description: '`created_at` or `rating`; prefix `-` for descending (default `-created_at`).', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $reviews = QueryBuilder::for(Review::query()->with('user', 'course.liveVersion'))
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('course_id'),
                AllowedFilter::exact('rating'),
                AllowedFilter::callback('q', fn (Builder $q, $term) => $q->where('body', 'ilike', '%'.addcslashes((string) $term, '%_\\').'%')),
            )
            ->allowedSorts('created_at', 'rating')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return AdminReviewResource::collection($reviews);
    }

    /** Off the course page and out of the ratings at once. */
    public function hide(StaffNoteRequest $request, Review $review, ModerateReview $moderate): AdminReviewResource
    {
        return $this->present($moderate->hide($review, $this->staff($request), (string) $request->note()));
    }

    public function restore(StaffNoteRequest $request, Review $review, ModerateReview $moderate): AdminReviewResource
    {
        return $this->present($moderate->restore($review, $this->staff($request), (string) $request->note()));
    }

    private function present(Review $review): AdminReviewResource
    {
        return new AdminReviewResource($review->load('user', 'course.liveVersion'));
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
