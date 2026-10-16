<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Catalog\Actions\BrowseCourses;
use App\Domain\Commerce\Models\Bundle;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commerce\BundleResource;

final class BundleController extends Controller
{
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
