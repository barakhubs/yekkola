<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use App\Domain\Authoring\Enums\MediaKind;
use App\Integrations\InvalidWebhookPayload;
use App\Integrations\InvalidWebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fake video host for local, staging, and tests. Refused in production.
 *
 * Uploads become assets that are ready immediately (markErrored() simulates failures). Tokens are opaque
 * strings encoding what was asked for, so tests can assert on them. A real browser upload flow for staging
 * (local upload endpoint + webhook) comes with Phase 1.5.
 */
final class FakeVideoProvider implements VideoProvider
{
    public const WEBHOOK_SIGNATURE_HEADER = 'X-Fake-Signature';

    private const PREFIX = 'fake-video:';

    public function __construct(
        private readonly Cache $cache,
        private readonly string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function createDirectUpload(DirectUploadRequest $request): DirectUpload
    {
        $uploadId = 'fake-upload-'.Str::ulid();
        $assetId = 'fake-asset-'.Str::ulid();

        $this->cache->put(self::PREFIX.$assetId, [
            'status' => VideoAssetStatus::Ready->value,
            'playback_id' => 'fake-playback-'.Str::ulid(),
            'passthrough' => $request->passthrough,
            'kind' => $request->kind->value,
        ], now()->addDays(30));
        $this->cache->put(self::PREFIX.'upload:'.$uploadId, $assetId, now()->addDays(30));

        return new DirectUpload($uploadId, 'https://uploads.fake.test/'.$uploadId);
    }

    public function getAsset(string $assetId): VideoAsset
    {
        /** @var array{status: string, playback_id: string, passthrough: string}|null $state */
        $state = $this->cache->get(self::PREFIX.$assetId);

        if ($state === null) {
            throw new VideoAssetNotFound("No asset [{$assetId}].");
        }

        $status = VideoAssetStatus::from($state['status']);

        return new VideoAsset(
            id: $assetId,
            status: $status,
            playbackId: $status === VideoAssetStatus::Ready ? $state['playback_id'] : null,
            durationSeconds: $status === VideoAssetStatus::Ready ? 1_200.0 : null,
            passthrough: $state['passthrough'],
            errorMessage: $status === VideoAssetStatus::Errored ? 'Simulated processing error.' : null,
        );
    }

    public function deleteAsset(string $assetId): void
    {
        $this->cache->forget(self::PREFIX.$assetId);
    }

    public function playbackTokens(string $playbackId, MediaKind $kind, int $ttlSeconds): PlaybackTokens
    {
        $expiresAt = CarbonImmutable::now()->addSeconds($ttlSeconds);
        $suffix = "{$playbackId}:{$expiresAt->getTimestamp()}";
        $isVideo = $kind === MediaKind::Video;

        return new PlaybackTokens(
            playbackToken: "fake-playback-token:{$suffix}",
            drmToken: $kind->supportsDrm() ? "fake-drm-token:{$suffix}" : null,
            expiresAt: $expiresAt,
            thumbnailToken: $isVideo ? "fake-thumbnail-token:{$suffix}" : null,
            storyboardToken: $isVideo ? "fake-storyboard-token:{$suffix}" : null,
        );
    }

    public function offlineLicenseToken(string $playbackId, int $licenseSeconds, int $ttlSeconds): string
    {
        if ($licenseSeconds <= 0) {
            throw new InvalidArgumentException('License duration must be positive.');
        }

        return "fake-offline-token:{$playbackId}:license={$licenseSeconds}";
    }

    public function audioDownloadUrl(string $playbackId, int $ttlSeconds): string
    {
        $expires = CarbonImmutable::now()->addSeconds($ttlSeconds)->getTimestamp();

        return "https://downloads.fake.test/{$playbackId}/audio.m4a?expires={$expires}";
    }

    public function parseWebhook(Request $request): VideoWebhookEvent
    {
        $expected = hash_hmac('sha256', $request->getContent(), $this->webhookSecret);

        if (! hash_equals($expected, (string) $request->header(self::WEBHOOK_SIGNATURE_HEADER))) {
            throw InvalidWebhookSignature::make();
        }

        if (! is_string($request->input('id')) || $request->input('id') === '') {
            throw InvalidWebhookPayload::missing('id');
        }

        return new VideoWebhookEvent(
            eventId: (string) $request->input('id'),
            type: VideoEventType::tryFrom((string) $request->input('type')) ?? VideoEventType::Other,
            assetId: $request->input('asset_id'),
            uploadId: $request->input('upload_id'),
            passthrough: $request->input('passthrough'),
            payload: $request->all(),
        );
    }

    /** Simulate a processing failure for an asset. */
    public function markErrored(string $assetId): void
    {
        $state = $this->cache->get(self::PREFIX.$assetId);
        if (is_array($state)) {
            $state['status'] = VideoAssetStatus::Errored->value;
            $this->cache->put(self::PREFIX.$assetId, $state, now()->addDays(30));
        }
    }

    public function assetIdForUpload(string $uploadId): ?string
    {
        $assetId = $this->cache->get(self::PREFIX.'upload:'.$uploadId);

        return is_string($assetId) ? $assetId : null;
    }

    /**
     * Build a correctly signed webhook request (tests and staging tools).
     *
     * @param  array<string, mixed>  $payload
     */
    public function webhookRequest(array $payload): Request
    {
        $body = (string) json_encode($payload);

        $request = Request::create('/api/v1/webhooks/video', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->headers->set(self::WEBHOOK_SIGNATURE_HEADER, hash_hmac('sha256', $body, $this->webhookSecret));

        return $request;
    }
}
