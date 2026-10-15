<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use App\Domain\Authoring\Enums\MediaKind;
use App\Integrations\InvalidWebhookPayload;
use App\Integrations\InvalidWebhookSignature;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * Mux Video over its REST API (https://api.mux.com), with signed playback/DRM tokens as RS256 JWTs.
 * (The official mux-php SDK needs Guzzle 7; Laravel 13 ships Guzzle 8.) See docs/research/mux.md.
 *
 * - Video: `plus` quality + DRM (advanced playback policy), burned-in watermark overlay.
 * - Audio: signed playback (no DRM on audio) + an audio-only static rendition for offline download.
 * - Webhooks: `Mux-Signature: t=<ts>,v1=<hmac>` over "<ts>.<body>", 5-minute tolerance.
 *
 * To verify in the Mux spike (docs/spikes/mux-drm-offline.md): DRM policy alongside signed thumbnails,
 * and the lowest allowed max_resolution_tier.
 */
final class MuxVideoProvider implements VideoProvider
{
    private const API = 'https://api.mux.com/video/v1';

    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    /**
     * @param  array{token_id: ?string, token_secret: ?string, signing_key_id: ?string, signing_private_key: ?string, webhook_secret: ?string, drm_configuration_id: ?string}  $config
     */
    public function __construct(
        private readonly Http $http,
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return 'mux';
    }

    public function createDirectUpload(DirectUploadRequest $request): DirectUpload
    {
        $settings = ['passthrough' => $request->passthrough];

        if ($request->kind === MediaKind::Video) {
            $settings['video_quality'] = 'plus'; // DRM requires plus
            $settings['advanced_playback_policies'] = [[
                'policy' => 'drm',
                'drm_configuration_id' => $this->required('drm_configuration_id'),
            ]];
            if ($request->watermarkUrl !== null) {
                $settings['inputs'] = [[
                    'url' => $request->watermarkUrl,
                    'overlay_settings' => [
                        'vertical_align' => 'bottom',
                        'vertical_margin' => '4%',
                        'horizontal_align' => 'right',
                        'horizontal_margin' => '4%',
                        'width' => '12%',
                        'opacity' => '70%',
                    ],
                ]];
            }
        } else {
            $settings['playback_policies'] = ['signed'];
            $settings['static_renditions'] = [['resolution' => 'audio-only']];
        }

        $data = $this->send(retry: false, call: fn (PendingRequest $http) => $http->post(self::API.'/uploads', [
            'cors_origin' => $request->corsOrigin,
            'new_asset_settings' => $settings,
        ]));

        return new DirectUpload((string) $data['id'], (string) $data['url']);
    }

    public function getAsset(string $assetId): VideoAsset
    {
        try {
            $data = $this->send(fn (PendingRequest $http) => $http->get(self::API.'/assets/'.rawurlencode($assetId)));
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                throw new VideoAssetNotFound("No Mux asset [{$assetId}].", 0, $e);
            }
            throw $e;
        }

        $status = match ($data['status'] ?? null) {
            'ready' => VideoAssetStatus::Ready,
            'errored' => VideoAssetStatus::Errored,
            default => VideoAssetStatus::Preparing,
        };

        $playbackId = $data['playback_ids'][0]['id'] ?? null;
        $errors = $data['errors']['messages'] ?? [];

        return new VideoAsset(
            id: (string) $data['id'],
            status: $status,
            playbackId: is_string($playbackId) ? $playbackId : null,
            durationSeconds: isset($data['duration']) ? (float) $data['duration'] : null,
            passthrough: isset($data['passthrough']) ? (string) $data['passthrough'] : null,
            errorMessage: $status === VideoAssetStatus::Errored ? implode(' ', (array) $errors) : null,
        );
    }

    public function deleteAsset(string $assetId): void
    {
        try {
            $this->send(fn (PendingRequest $http) => $http->delete(self::API.'/assets/'.rawurlencode($assetId)), expectJson: false);
        } catch (RequestException $e) {
            if ($e->response->status() !== 404) {
                throw $e;
            }
        }
    }

    public function playbackTokens(string $playbackId, MediaKind $kind, int $ttlSeconds): PlaybackTokens
    {
        $expiresAt = CarbonImmutable::now()->addSeconds($ttlSeconds);
        $isVideo = $kind === MediaKind::Video;

        return new PlaybackTokens(
            playbackToken: $this->sign($playbackId, 'v', $expiresAt),
            drmToken: $kind->supportsDrm() ? $this->sign($playbackId, 'd', $expiresAt) : null,
            expiresAt: $expiresAt,
            thumbnailToken: $isVideo ? $this->sign($playbackId, 't', $expiresAt) : null,
            storyboardToken: $isVideo ? $this->sign($playbackId, 's', $expiresAt) : null,
        );
    }

    public function offlineLicenseToken(string $playbackId, int $licenseSeconds, int $ttlSeconds): string
    {
        if ($licenseSeconds <= 0) {
            throw new InvalidArgumentException('License duration must be positive.');
        }

        return $this->sign($playbackId, 'd', CarbonImmutable::now()->addSeconds($ttlSeconds), [
            'offline' => true,
            'licenseExpiration' => $licenseSeconds,
        ]);
    }

    public function audioDownloadUrl(string $playbackId, int $ttlSeconds): string
    {
        $token = $this->sign($playbackId, 'v', CarbonImmutable::now()->addSeconds($ttlSeconds));

        return 'https://stream.mux.com/'.rawurlencode($playbackId).'/audio.m4a?token='.$token;
    }

    public function parseWebhook(Request $request): VideoWebhookEvent
    {
        $this->verifySignature((string) $request->header('Mux-Signature'), $request->getContent());

        $type = (string) $request->input('type');
        $data = (array) $request->input('data', []);
        $eventId = $request->input('id');

        if (! is_string($eventId) || $eventId === '') {
            throw InvalidWebhookPayload::missing('id');
        }

        [$mapped, $assetId, $uploadId] = match ($type) {
            'video.upload.asset_created' => [VideoEventType::AssetCreated, $data['asset_id'] ?? null, $data['id'] ?? null],
            'video.upload.cancelled' => [VideoEventType::UploadCancelled, null, $data['id'] ?? null],
            'video.asset.ready' => [VideoEventType::AssetReady, $data['id'] ?? null, $data['upload_id'] ?? null],
            'video.asset.errored' => [VideoEventType::AssetErrored, $data['id'] ?? null, $data['upload_id'] ?? null],
            default => [VideoEventType::Other, null, null],
        };

        return new VideoWebhookEvent(
            eventId: $eventId,
            type: $mapped,
            assetId: is_string($assetId) ? $assetId : null,
            uploadId: is_string($uploadId) ? $uploadId : null,
            passthrough: isset($data['passthrough']) && is_string($data['passthrough'])
                ? $data['passthrough']
                : (isset($data['new_asset_settings']['passthrough']) ? (string) $data['new_asset_settings']['passthrough'] : null),
            payload: $request->all(),
        );
    }

    private function verifySignature(string $header, string $body): void
    {
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if ($timestamp === '' || $signature === '' || ! ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            throw InvalidWebhookSignature::make();
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $this->required('webhook_secret'));

        if (! hash_equals($expected, $signature)) {
            throw InvalidWebhookSignature::make();
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function sign(string $playbackId, string $audience, CarbonImmutable $expiresAt, array $claims = []): string
    {
        $privateKey = base64_decode($this->required('signing_private_key'), true);
        if ($privateKey === false) {
            throw new RuntimeException('MUX_SIGNING_PRIVATE_KEY must be base64-encoded PEM.');
        }

        return JWT::encode(
            ['sub' => $playbackId, 'aud' => $audience, 'exp' => $expiresAt->getTimestamp(), ...$claims],
            $privateKey,
            'RS256',
            $this->required('signing_key_id'),
        );
    }

    /**
     * @param  callable(PendingRequest): \Illuminate\Http\Client\Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call, bool $expectJson = true, bool $retry = true): array
    {
        $http = $this->http
            ->withBasicAuth($this->required('token_id'), $this->required('token_secret'))
            ->acceptJson()
            ->timeout(15);

        if ($retry) { // POSTs aren't idempotent: a retried timeout could create a second upload
            $http = $http->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: true);
        }

        $response = $call($http)->throw();

        return $expectJson ? (array) $response->json('data', []) : [];
    }

    private function required(string $key): string
    {
        $value = $this->config[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Mux is not configured: missing yekkola.mux.{$key}.");
        }

        return $value;
    }
}
