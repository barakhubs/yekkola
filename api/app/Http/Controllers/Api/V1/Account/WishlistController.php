<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Catalog\Actions\BrowseCourses;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\AddToWishlist;
use App\Domain\Learning\Actions\RemoveFromWishlist;
use App\Domain\Learning\Models\WishlistItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\WishlistRequest;
use App\Http\Resources\Catalog\CourseCardResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The signed-in user's wishlist (PRD-04 FR-08): listed courses only, newest saved first.
 */
final class WishlistController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $courses = WishlistItem::coursesFor($this->user($request)->id)
            ->with(BrowseCourses::cardRelations())
            ->paginate($this->perPage($request));

        return CourseCardResource::collection($courses);
    }

    /**
     * Saves a listed course. Idempotent.
     */
    public function store(WishlistRequest $request, AddToWishlist $add): Response
    {
        $add->handle($this->user($request), $request->string('course_id')->toString());

        return response()->noContent();
    }

    public function destroy(Request $request, string $course, RemoveFromWishlist $remove): Response
    {
        $remove->handle($this->user($request), $course);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
