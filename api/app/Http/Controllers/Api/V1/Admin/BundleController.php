<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Actions\BrowseCourses;
use App\Domain\Commerce\Actions\SaveBundle;
use App\Domain\Commerce\Models\Bundle;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\BundleRequest;
use App\Http\Requests\Commerce\UpdateBundleRequest;
use App\Http\Resources\Commerce\BundleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/** Bundles in the back office (PRD-09 FR-13): platform bundles and every professor's. */
final class BundleController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[title]', description: 'Part of the title.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[status]', description: 'draft, active or archived.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[currency]', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('sort', description: '`created_at`, `title` or `price_minor`; prefix `-` for descending (default `-created_at`).', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $bundles = QueryBuilder::for(Bundle::query()->with(['professor', 'courses' => fn ($q) => $q->with(BrowseCourses::cardRelations())]))
            ->allowedFilters(AllowedFilter::exact('status'), AllowedFilter::exact('currency'), AllowedFilter::partial('title'))
            ->allowedSorts('created_at', 'title', 'price_minor')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return BundleResource::collection($bundles);
    }

    public function store(BundleRequest $request, SaveBundle $save): JsonResponse
    {
        $bundle = $save->handle($request->validated(), $this->user($request));

        return (new BundleResource($this->present($bundle)))->response()->setStatusCode(201);
    }

    public function update(UpdateBundleRequest $request, Bundle $bundle, SaveBundle $save): BundleResource
    {
        return new BundleResource($this->present($save->handle($request->validated(), $this->user($request), $bundle, $bundle->professor)));
    }

    private function present(Bundle $bundle): Bundle
    {
        return $bundle->load(['professor', 'courses' => fn ($q) => $q->with(BrowseCourses::cardRelations())]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
