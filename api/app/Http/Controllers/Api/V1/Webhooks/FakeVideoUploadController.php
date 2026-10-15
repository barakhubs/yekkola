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
 * processed exactly like a provider webhook. Unavailable with real drivers.
 */
final class FakeVideoUploadController extends Controller
{
    public function __invoke(Request $request, string $upload, VideoProvider $videos, SyncMediaAsset $sync): Response
    {
        abort_unless($videos instanceof FakeVideoProvider, 404);

        $assetId = $videos->completeUpload($upload);
        abort_if($assetId === null, 404);

        $asset = $videos->getAsset($assetId);
        $sync->fromEvent(new VideoWebhookEvent('fake-'.$upload, VideoEventType::AssetReady, $assetId, $upload, $asset->passthrough, []));

        return response()->noContent();
    }
}
