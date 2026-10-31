<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Actions\ManageCategories;
use App\Domain\Catalog\Models\Category;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Http\Resources\Admin\AdminCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The category tree in the back office (PRD-09 FR-09): every category (hidden ones too), create, edit, delete
 * when unused, and order each level.
 */
final class CategoryController extends Controller
{
    /**
     * All categories, top level first then each one's sub-categories, in display order.
     */
    public function index(): AnonymousResourceCollection
    {
        return AdminCategoryResource::collection($this->tree());
    }

    public function store(CategoryRequest $request, ManageCategories $manage): JsonResponse
    {
        $category = $manage->create($request->categoryData(), $this->staff($request));

        return (new AdminCategoryResource($this->counted($category)))->response()->setStatusCode(201);
    }

    public function update(CategoryRequest $request, Category $category, ManageCategories $manage): AdminCategoryResource
    {
        return new AdminCategoryResource($this->counted($manage->update($category, $request->categoryData(), $this->staff($request))));
    }

    /**
     * Only when no course and no sub-category uses it (409 `category.in_use`): hide it otherwise.
     */
    public function destroy(Request $request, Category $category, ManageCategories $manage): Response
    {
        $manage->delete($category, $this->staff($request));

        return response()->noContent();
    }

    /**
     * Orders one level: `ids` are exactly the categories under `parent_id` (null: the top level), in display order.
     */
    public function order(Request $request, ManageCategories $manage): AnonymousResourceCollection
    {
        /** @var array{parent_id?: string|null, ids: list<string>} $data */
        $data = $request->validate([
            'parent_id' => ['nullable', 'string', 'exists:categories,id'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string', 'distinct'],
        ]);
        $manage->reorder($data['parent_id'] ?? null, $data['ids'], $this->staff($request));

        return AdminCategoryResource::collection($this->tree());
    }

    /**
     * @return \Illuminate\Support\Collection<int, Category>
     */
    private function tree(): \Illuminate\Support\Collection
    {
        $all = Category::query()->withCount('courses', 'children')->orderBy('position')->orderBy('id')->get();
        $top = $all->whereNull('parent_id');

        return $top->flatMap(fn (Category $parent) => collect([$parent])->concat($all->where('parent_id', $parent->id)))->values();
    }

    private function counted(Category $category): Category
    {
        return Category::query()->withCount('courses', 'children')->findOrFail($category->id);
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
