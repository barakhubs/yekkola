<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\Wishlist;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\CourseCardResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The signed-in user's wishlist (PRD-04 FR-08).
 */
final class WishlistController extends Controller
{
    public function index(Request $request, Wishlist $wishlist): AnonymousResourceCollection
    {
        return CourseCardResource::collection($wishlist->list($this->user($request), $this->perPage($request)));
    }

    /**
     * Saves a listed course. Idempotent.
     */
    public function store(Request $request, Wishlist $wishlist): Response
    {
        $request->validate(['course_id' => ['required', 'ulid']]);

        $course = Course::query()->listed()->findOrFail($request->string('course_id')->toString());
        $wishlist->add($this->user($request), $course);

        return response()->noContent();
    }

    public function destroy(Request $request, string $course, Wishlist $wishlist): Response
    {
        $wishlist->remove($this->user($request), $course);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
