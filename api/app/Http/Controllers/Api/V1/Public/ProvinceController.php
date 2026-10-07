<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Platform\Models\Province;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProvinceResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProvinceController extends Controller
{
    /**
     * The 26 DRC provinces, alphabetically.
     */
    public function index(): AnonymousResourceCollection
    {
        return ProvinceResource::collection(Province::query()->alphabetical()->get());
    }
}
