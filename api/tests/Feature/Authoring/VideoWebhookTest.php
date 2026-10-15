<?php

declare(strict_types=1);

use App\Domain\Authoring\Enums\MediaAssetStatus;
use App\Domain\Authoring\Enums\MediaKind;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Platform\Models\WebhookEvent;
use App\Integrations\Video\DirectUploadRequest;
use App\Integrations\Video\VideoProvider;

beforeEach(function () {
    $this->professor = actingAsProfessor();
    $this->videos = app(VideoProvider::class);
    $this->asset = MediaAsset::query()->create([
        'professor_profile_id' => $this->professor->id,
        'kind' => MediaKind::Video,
        'provider' => 'fake',
        'status' => MediaAssetStatus::WaitingUpload,
    ]);
    $this->upload = $this->videos->createDirectUpload(new DirectUploadRequest(MediaKind::Video, $this->asset->id, 'http://localhost:3000'));
    $this->asset->update(['provider_upload_id' => $this->upload->uploadId]);
    $this->providerAssetId = $this->videos->assetIdForUpload($this->upload->uploadId);
});

function sendVideoWebhook(array $payload): Illuminate\Testing\TestResponse
{
    $request = test()->videos->webhookRequest($payload);

    return test()->call('POST', '/api/v1/webhooks/video', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $request->headers->get('X-Fake-Signature'),
    ], $request->getContent());
}

it('stores the event once, re-fetches the asset, and marks it ready', function () {
    $this->videos->completeUpload($this->upload->uploadId);
    $payload = ['id' => 'evt-1', 'type' => 'asset_ready', 'asset_id' => $this->providerAssetId, 'upload_id' => $this->upload->uploadId, 'passthrough' => $this->asset->id];

    sendVideoWebhook($payload)->assertOk();
    sendVideoWebhook($payload)->assertOk(); // duplicate delivery

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->sole()->processed_at)->not->toBeNull()
        ->and($this->asset->refresh()->status)->toBe(MediaAssetStatus::Ready)
        ->and($this->asset->provider_asset_id)->toBe($this->providerAssetId)
        ->and($this->asset->duration_seconds)->toBe(1_200);
});

it('trusts the provider, not the webhook body', function () {
    // The event claims "ready" but the provider still says processing.
    sendVideoWebhook(['id' => 'evt-2', 'type' => 'asset_ready', 'asset_id' => $this->providerAssetId, 'passthrough' => $this->asset->id])->assertOk();

    expect($this->asset->refresh()->status)->toBe(MediaAssetStatus::Processing);
});

it('records processing errors', function () {
    $this->videos->completeUpload($this->upload->uploadId);
    $this->videos->markErrored($this->providerAssetId);

    sendVideoWebhook(['id' => 'evt-3', 'type' => 'asset_errored', 'asset_id' => $this->providerAssetId, 'passthrough' => $this->asset->id])->assertOk();

    expect($this->asset->refresh()->status)->toBe(MediaAssetStatus::Errored)
        ->and($this->asset->error)->not->toBeNull();
});

it('rejects forged webhooks', function () {
    $this->call('POST', '/api/v1/webhooks/video', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => 'forged'], '{"id":"x"}')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'webhook.invalid_signature');

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('closes the fake upload endpoint when a real video driver is configured', function () {
    config(['yekkola.drivers.video' => 'mux', 'yekkola.mux' => ['token_id' => 'x']]);
    app()->forgetInstance(VideoProvider::class);

    $this->put('/api/v1/_fake/video-uploads/'.$this->upload->uploadId)->assertNotFound();
});
