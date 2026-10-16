<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Catalog\Actions\BrowseCourses;
use App\Domain\Commerce\Actions\SaveBundle;
use App\Domain\Commerce\Models\Bundle;
use App\Domain\Identity\Models\User;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\BundleRequest;
use App\Http\Resources\Commerce\BundleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/** A professor's bundles of their own courses (PRD-05 FR-13). */
final class BundleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $bundles = QueryBuilder::for(Bundle::query()->where('professor_profile_id', $this->professor($request)->id)->with(['courses' => fn ($q) => $q->with(BrowseCourses::cardRelations())]))
            ->allowedFilters(AllowedFilter::exact('status'), AllowedFilter::exact('currency'), AllowedFilter::partial('title'))
            ->allowedSorts('created_at', 'title', 'price_minor')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return BundleResource::collection($bundles);
    }

    public function store(BundleRequest $request, SaveBundle $save): JsonResponse
    {
        $bundle = $save->handle($request->validated(), $this->user($request), professor: $this->professor($request));

        return (new BundleResource($bundle->load(['courses' => fn ($q) => $q->with(BrowseCourses::cardRelations())])))->response()->setStatusCode(201);
    }

    public function update(BundleRequest $request, string $bundle, SaveBundle $save): BundleResource
    {
        $professor = $this->professor($request);
        $model = Bundle::query()->where('professor_profile_id', $professor->id)->findOrFail($bundle);

        return new BundleResource($save->handle($request->validated(), $this->user($request), $model, $professor)
            ->load(['courses' => fn ($q) => $q->with(BrowseCourses::cardRelations())]));
    }

    private function professor(Request $request): ProfessorProfile
    {
        return ProfessorProfile::query()->where('user_id', $this->user($request)->id)->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
