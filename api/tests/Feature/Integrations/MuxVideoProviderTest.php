<?php

declare(strict_types=1);

use App\Domain\Authoring\Enums\MediaKind;
use App\Integrations\InvalidWebhookSignature;
use App\Integrations\Video\DirectUploadRequest;
use App\Integrations\Video\MuxVideoProvider;
use App\Integrations\Video\VideoAssetNotFound;
use App\Integrations\Video\VideoAssetStatus;
use App\Integrations\Video\VideoEventType;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    [$private, $this->publicKey] = rsaKeyPair();
    $this->config = [
        'token_id' => 'token-id',
        'token_secret' => 'token-secret',
        'signing_key_id' => 'kid-123',
        'signing_private_key' => $private,
        'webhook_secret' => 'whsec',
        'drm_configuration_id' => 'drm-cfg',
    ];
    $this->mux = fn () => new MuxVideoProvider(app(Factory::class), $this->config);
});

function muxWebhook(array $payload, string $secret = 'whsec', ?int $timestamp = null): Request
{
    $body = (string) json_encode($payload);
    $t = $timestamp ?? time();
    $request = Request::create('/api/v1/webhooks/video', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
    $request->headers->set('Mux-Signature', "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret));

    return $request;
}

it('creates DRM video uploads with the watermark and plus quality', function () {
    Http::fake(['api.mux.com/video/v1/uploads' => Http::response(['data' => ['id' => 'up-1', 'url' => 'https://storage.mux.test/up-1']], 201)]);

    $upload = ($this->mux)()->createDirectUpload(new DirectUploadRequest(MediaKind::Video, 'asset-ulid', 'https://www.yekkola.test', 'https://cdn.yekkola.test/logo.png'));

    expect($upload->uploadId)->toBe('up-1');
    Http::assertSent(function (ClientRequest $r) {
        $s = $r['new_asset_settings'];

        return $r->hasHeader('Authorization', 'Basic '.base64_encode('token-id:token-secret'))
            && $r['cors_origin'] === 'https://www.yekkola.test'
            && $s['video_quality'] === 'plus'
            && $s['advanced_playback_policies'][0] === ['policy' => 'drm', 'drm_configuration_id' => 'drm-cfg']
            && $s['passthrough'] === 'asset-ulid'
            && $s['inputs'][0]['url'] === 'https://cdn.yekkola.test/logo.png';
    });
});

it('creates signed audio uploads with a downloadable audio rendition', function () {
    Http::fake(['api.mux.com/*' => Http::response(['data' => ['id' => 'up-2', 'url' => 'https://storage.mux.test/up-2']], 201)]);

    ($this->mux)()->createDirectUpload(new DirectUploadRequest(MediaKind::Audio, 'asset-ulid', 'https://www.yekkola.test'));

    Http::assertSent(fn (ClientRequest $r) => $r['new_asset_settings']['playback_policies'] === ['signed']
        && $r['new_asset_settings']['static_renditions'] === [['resolution' => 'audio-only']]
        && ! isset($r['new_asset_settings']['advanced_playback_policies']));
});

it('maps asset status and reports missing assets as not found', function () {
    Http::fake([
        'api.mux.com/video/v1/assets/a-ready' => Http::response(['data' => ['id' => 'a-ready', 'status' => 'ready', 'duration' => 754.2, 'passthrough' => 'p1', 'playback_ids' => [['id' => 'pb-1', 'policy' => 'drm']]]]),
        'api.mux.com/video/v1/assets/a-missing' => Http::response(['error' => ['type' => 'not_found']], 404),
    ]);

    $asset = ($this->mux)()->getAsset('a-ready');

    expect($asset->status)->toBe(VideoAssetStatus::Ready)
        ->and($asset->playbackId)->toBe('pb-1')
        ->and($asset->durationSeconds)->toBe(754.2)
        ->and(fn () => ($this->mux)()->getAsset('a-missing'))->toThrow(VideoAssetNotFound::class);
});

it('signs playback, DRM, thumbnail, and storyboard tokens with the signing key', function () {
    $tokens = ($this->mux)()->playbackTokens('pb-1', MediaKind::Video, 3_600);

    $claims = fn (string $jwt) => (array) JWT::decode($jwt, new Key($this->publicKey, 'RS256'));
    [$header] = explode('.', $tokens->playbackToken);

    expect($claims($tokens->playbackToken))->toMatchArray(['sub' => 'pb-1', 'aud' => 'v'])
        ->and($claims((string) $tokens->drmToken)['aud'])->toBe('d')
        ->and($claims((string) $tokens->thumbnailToken)['aud'])->toBe('t')
        ->and($claims((string) $tokens->storyboardToken)['aud'])->toBe('s')
        ->and(json_decode(base64_decode($header), true)['kid'])->toBe('kid-123');
});

it('issues offline DRM licenses and signed audio download links', function () {
    $offline = (array) JWT::decode(($this->mux)()->offlineLicenseToken('pb-1', 1_209_600, 600), new Key($this->publicKey, 'RS256'));
    $url = ($this->mux)()->audioDownloadUrl('pb-a', 600);

    expect($offline)->toMatchArray(['aud' => 'd', 'offline' => true, 'licenseExpiration' => 1_209_600])
        ->and($url)->toStartWith('https://stream.mux.com/pb-a/audio.m4a?token=');
});

it('accepts correctly signed webhooks and maps event types', function () {
    $event = ($this->mux)()->parseWebhook(muxWebhook([
        'id' => 'evt-1', 'type' => 'video.asset.ready',
        'data' => ['id' => 'a-1', 'upload_id' => 'up-1', 'passthrough' => 'asset-ulid'],
    ]));

    expect($event->type)->toBe(VideoEventType::AssetReady)
        ->and($event->assetId)->toBe('a-1')
        ->and($event->uploadId)->toBe('up-1')
        ->and($event->passthrough)->toBe('asset-ulid');
});

it('rejects webhooks with a wrong secret or an old timestamp', function (string $secret, int $age) {
    ($this->mux)()->parseWebhook(muxWebhook(['id' => 'e', 'type' => 'video.asset.ready', 'data' => []], $secret, time() - $age));
})->throws(InvalidWebhookSignature::class)->with([
    'wrong secret' => ['other', 0],
    'replayed after 10 minutes' => ['whsec', 600],
]);

it('refuses to run without configuration', function () {
    $this->config['drm_configuration_id'] = null;
    Http::fake();

    ($this->mux)()->createDirectUpload(new DirectUploadRequest(MediaKind::Video, 'x', 'https://www.yekkola.test'));
})->throws(RuntimeException::class, 'drm_configuration_id');
