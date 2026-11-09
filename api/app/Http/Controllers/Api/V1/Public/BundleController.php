<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Catalog\Actions\BrowseCourses;
use App\Domain\Commerce\Models\Bundle;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commerce\BundleResource;
use Illuminate\Http\Request;

final class BundleController extends Controller
{
    /**
     * Bundles on sale, newest first: just enough for the sitemap and links (slug, title, last change).
     *
     * @return array{data: list<array{slug: string, title: string, updated_at: string}>, meta: array{current_page: int, last_page: int}}
     */
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 100)]
    public function index(Request $request): array
    {
        $page = Bundle::query()->onSale()->orderByDesc('updated_at')->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 100))), ['slug', 'title', 'updated_at']);

        return [
            'data' => array_values(array_map(fn (Bundle $b): array => [
                'slug' => $b->slug,
                'title' => $b->title,
                'updated_at' => $b->updated_at->toIso8601String(),
            ], $page->items())),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ];
    }

    /**
     * A bundle on sale: active, with every course published (PRD-05 FR-13); otherwise not found.
     */
    public function show(string $slug): BundleResource
    {
        return new BundleResource(Bundle::query()->onSale()->where('slug', $slug)
            ->with(['courses' => fn ($q) => $q->with(BrowseCourses::cardRelations())])
            ->firstOrFail());
    }
}
