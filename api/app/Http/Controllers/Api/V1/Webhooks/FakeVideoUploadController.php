<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Domain\Authoring\Actions\SyncMediaAsset;
use App\Http\Controllers\Controller;
use App\Integrations\Video\FakeVideoProvider;
use App\Integrations\Video\VideoEventType;
use App\Integrations\Video\VideoProvider;
use App\Integrations\Video\VideoWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stand-in for the provider's upload URL when the fake video driver is active (local, staging), so the real
 * studio upload flow works end to end: the browser PUTs the file here, the asset becomes ready, and it is
 * processed exactly like a provider webhook. Chunked uploads (UpChunk, like Mux) send one PUT per chunk with a
 * `Content-Range`; the upload completes on the last one. Unavailable with real drivers.
 */
final class FakeVideoUploadController extends Controller
{
    public function __invoke(Request $request, string $upload, VideoProvider $videos, SyncMediaAsset $sync): Response
    {
        abort_unless($videos instanceof FakeVideoProvider, 404);

        if (! $this->isLastChunk($request->header('Content-Range'))) {
            abort_unless($videos->hasPendingUpload($upload), 404);

            return response()->noContent();
        }

        $assetId = $videos->completeUpload($upload);
        abort_if($assetId === null, 404);

        $asset = $videos->getAsset($assetId);
        $sync->fromEvent(new VideoWebhookEvent('fake-'.$upload, VideoEventType::AssetReady, $assetId, $upload, $asset->passthrough, []));

        return response()->noContent();
    }

    /** No range (a single PUT) or `bytes {first}-{last}/{total}` with last = total - 1. */
    private function isLastChunk(?string $range): bool
    {
        if ($range === null || preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/', trim($range), $m) !== 1) {
            return true;
        }

        return (int) $m[2] + 1 >= (int) $m[3];
    }
}
