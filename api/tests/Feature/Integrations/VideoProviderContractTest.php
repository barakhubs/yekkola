<?php

declare(strict_types=1);

use App\Domain\Authoring\Enums\MediaKind;
use App\Integrations\InvalidWebhookSignature;
use App\Integrations\Video\DirectUploadRequest;
use App\Integrations\Video\FakeVideoProvider;
use App\Integrations\Video\VideoAssetStatus;
use App\Integrations\Video\VideoEventType;
use App\Integrations\Video\VideoProvider;
use Illuminate\Http\Request;

/*
| Contract every VideoProvider must satisfy. Add the Mux driver to the dataset in Phase 1.5
| (with Http::fake() for the Mux API).
*/

dataset('video providers', [
    'fake' => fn (): VideoProvider => new FakeVideoProvider(cache()->store(), 'secret'),
]);

it('creates a direct upload URL for the browser', function (Closure $make, MediaKind $kind) {
    $upload = $make()->createDirectUpload(new DirectUploadRequest($kind, 'media-1', 'https://studio.yekkola.test'));

    expect($upload->uploadId)->not->toBeEmpty()
        ->and($upload->url)->toStartWith('https://');
})->with('video providers')->with([MediaKind::Video, MediaKind::Audio]);

it('issues a DRM token for video but not for audio', function (Closure $make) {
    $provider = $make();

    $video = $provider->playbackTokens('pb-1', MediaKind::Video, 3_600);
    $audio = $provider->playbackTokens('pb-2', MediaKind::Audio, 3_600);

    expect($video->drmToken)->not->toBeNull()
        ->and($audio->drmToken)->toBeNull()
        ->and($audio->playbackToken)->not->toBeEmpty()
        ->and($video->expiresAt->isFuture())->toBeTrue();
})->with('video providers');

it('issues offline license tokens', function (Closure $make) {
    expect($make()->offlineLicenseToken('pb-1', 14 * 86_400, 3_600))->not->toBeEmpty();
})->with('video providers');

it('rejects webhooks with a bad signature', function (Closure $make) {
    $request = Request::create('/api/v1/webhooks/video', 'POST', content: '{"id":"1"}');
    $request->headers->set(FakeVideoProvider::WEBHOOK_SECRET_HEADER, 'forged');

    $make()->parseWebhook($request);
})->with('video providers')->throws(InvalidWebhookSignature::class);

// Fake-specific behaviour

it('makes uploaded assets ready, and can simulate processing errors', function () {
    $provider = new FakeVideoProvider(cache()->store(), 'secret');
    $upload = $provider->createDirectUpload(new DirectUploadRequest(MediaKind::Video, 'media-1', 'https://studio.yekkola.test'));
    $assetId = $provider->assetIdForUpload($upload->uploadId);

    $asset = $provider->getAsset($assetId);
    expect($asset->status)->toBe(VideoAssetStatus::Ready)
        ->and($asset->playbackId)->not->toBeNull()
        ->and($asset->passthrough)->toBe('media-1');

    $provider->markErrored($assetId);
    expect($provider->getAsset($assetId)->status)->toBe(VideoAssetStatus::Errored);
});

it('parses webhooks it signed itself, ignoring unknown event types', function () {
    $provider = new FakeVideoProvider(cache()->store(), 'secret');

    $ready = $provider->parseWebhook($provider->webhookRequest(['id' => 'evt-1', 'type' => 'asset_ready', 'asset_id' => 'a-1', 'passthrough' => 'media-1']));
    $other = $provider->parseWebhook($provider->webhookRequest(['id' => 'evt-2', 'type' => 'something.new']));

    expect($ready->type)->toBe(VideoEventType::AssetReady)
        ->and($ready->passthrough)->toBe('media-1')
        ->and($other->type)->toBe(VideoEventType::Other);
});
