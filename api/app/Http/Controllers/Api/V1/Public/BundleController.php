<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Actions\BrowseCourses;
use App\Domain\Commerce\Enums\BundleStatus;
use App\Domain\Commerce\Models\Bundle;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commerce\BundleResource;

final class BundleController extends Controller
{
    /**
     * An active bundle whose courses are all listed (PRD-05 FR-13); otherwise not found.
     */
    public function show(string $slug): BundleResource
    {
        $bundle = Bundle::query()->where('slug', $slug)->where('status', BundleStatus::Active)
            ->with(['courses' => fn ($q) => $q->with(BrowseCourses::cardRelations())])
            ->firstOrFail();

        $listed = Course::query()->listed()->whereIn('id', $bundle->courses->modelKeys())->count();
        abort_if($listed !== $bundle->courses->count(), 404);

        return new BundleResource($bundle);
    }
}
