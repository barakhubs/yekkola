<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Authoring\Actions\SyncMediaAsset;
use App\Domain\Authoring\Enums\MediaAssetStatus;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\Authoring\MediaAssetResource;
use Illuminate\Http\Request;

final class MediaController extends Controller
{
    /**
     * Processing status of an uploaded file (the studio polls this). Re-checks the provider while processing,
     * in case a webhook was delayed.
     */
    public function show(Request $request, string $media, SyncMediaAsset $sync): MediaAssetResource
    {
        /** @var ProfessorProfile $professor */
        $professor = $request->attributes->get('professor_profile');
        $asset = MediaAsset::query()->where('professor_profile_id', $professor->id)->findOrFail($media);

        if ($asset->status === MediaAssetStatus::Processing) {
            $asset = $sync->refresh($asset);
        }

        return new MediaAssetResource($asset);
    }
}
