<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Models\Category;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\CategoryResource;
use Illuminate\Http\Request;

final class CategoryController extends Controller
{
    /**
     * The visible category tree (PRD-04 FR-02), in admin order, with listed-course counts.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function index(Request $request): array
    {
        /** @var array<string, int> $counts */
        $counts = Course::query()->listed()->whereNotNull('category_id')
            ->groupBy('category_id')->selectRaw('category_id, count(*) as total')
            ->pluck('total', 'category_id')->map(fn ($n) => (int) $n)->all();

        $roots = Category::query()->visible()->whereNull('parent_id')->orderBy('position')
            ->with(['children' => fn ($q) => $q->where('is_visible', true)])
            ->get();

        return ['data' => $roots->map(fn (Category $c) => (new CategoryResource($c, $counts))->toArray($request))->values()->all()];
    }
}
