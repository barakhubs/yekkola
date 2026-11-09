<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Authoring\Actions\MediaUploads;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Authoring\CompleteMediaUploadRequest;
use App\Http\Requests\Authoring\MediaPartsRequest;
use App\Http\Resources\Authoring\MediaAssetResource;
use Illuminate\Http\Request;

/** A professor's uploaded video/audio files (PRD-03 FR-04/05). */
final class MediaController extends Controller
{
    /** Processing status of an uploaded file (the studio polls this until `ready` or `errored`). */
    public function show(Request $request, string $media): MediaAssetResource
    {
        return new MediaAssetResource($this->asset($request, $media));
    }

    /**
     * Fresh pre-signed URLs for some parts of an open upload (the next batch, or one whose URL expired).
     *
     * @return array{data: array{parts: list<array{number: int, url: string}>}}
     */
    public function parts(MediaPartsRequest $request, string $media, MediaUploads $uploads): array
    {
        /** @var list<int> $numbers */
        $numbers = array_map('intval', (array) $request->validated('parts'));

        return ['data' => ['parts' => $uploads->partUrls($this->asset($request, $media), $numbers)]];
    }

    /**
     * Confirm the upload once every part is sent: the file is checked and queued for processing (`processing`).
     * Calling it again after success is harmless.
     */
    public function complete(CompleteMediaUploadRequest $request, string $media, MediaUploads $uploads): MediaAssetResource
    {
        /** @var list<array{number: int, etag: string}> $parts */
        $parts = array_map(
            fn (array $p): array => ['number' => (int) $p['number'], 'etag' => (string) $p['etag']],
            (array) $request->validated('parts'),
        );

        return new MediaAssetResource($uploads->complete($this->asset($request, $media), $parts));
    }

    private function asset(Request $request, string $id): MediaAsset
    {
        /** @var ProfessorProfile $professor */
        $professor = $request->attributes->get('professor_profile');

        return MediaAsset::query()->where('professor_profile_id', $professor->id)->findOrFail($id);
    }
}
