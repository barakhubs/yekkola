<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicProfessorResource;

final class ProfessorController extends Controller
{
    /**
     * A professor's public page (courses are added with the catalogue).
     */
    public function show(string $slug): PublicProfessorResource
    {
        $profile = ProfessorProfile::query()->public()->with('province')->where('slug', $slug)->firstOrFail();

        return new PublicProfessorResource($profile);
    }
}
