<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use App\Domain\Authoring\Enums\MediaKind;
use App\Integrations\InvalidWebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fake video host for local, staging, and tests. Refused in production.
 *
 * Uploads become assets that are ready immediately (use markErrored() to simulate failures).
 * Tokens are opaque strings that encode what was asked for, so tests can assert on them.
 */
final class FakeVideoProvider implements VideoProvider
{
    public const WEBHOOK_SECRET_HEADER = 'X-Fake-Signature';

    private const CACHE_PREFIX = 'fake-video:';

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

        $this->cache->forever(self::CACHE_PREFIX.$assetId, [
            'status' => VideoAssetStatus::Ready->value,
            'playback_id' => 'fake-playback-'.Str::ulid(),
            'passthrough' => $request->passthrough,
            'kind' => $request->kind->value,
            'upload_id' => $uploadId,
        ]);
        $this->cache->forever(self::CACHE_PREFIX.'upload:'.$uploadId, $assetId);

        return new DirectUpload($uploadId, 'https://uploads.fake.test/'.$uploadId);
    }

    public function getAsset(string $assetId): VideoAsset
    {
        /** @var array{status: string, playback_id: string, passthrough: string}|null $state */
        $state = $this->cache->get(self::CACHE_PREFIX.$assetId);

        if ($state === null) {
            return new VideoAsset($assetId, VideoAssetStatus::Errored, errorMessage: 'Asset not found.');
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
        $this->cache->forget(self::CACHE_PREFIX.$assetId);
    }

    public function playbackTokens(string $playbackId, MediaKind $kind, int $ttlSeconds): PlaybackTokens
    {
        $expiresAt = CarbonImmutable::now()->addSeconds($ttlSeconds);

        return new PlaybackTokens(
            playbackToken: "fake-playback-token:{$playbackId}:{$expiresAt->getTimestamp()}",
            drmToken: $kind->supportsDrm() ? "fake-drm-token:{$playbackId}:{$expiresAt->getTimestamp()}" : null,
            expiresAt: $expiresAt,
        );
    }

    public function offlineLicenseToken(string $playbackId, int $licenseSeconds, int $ttlSeconds): string
    {
        if ($licenseSeconds <= 0) {
            throw new InvalidArgumentException('License duration must be positive.');
        }

        return "fake-offline-token:{$playbackId}:license={$licenseSeconds}";
    }

    public function parseWebhook(Request $request): VideoWebhookEvent
    {
        $expected = hash_hmac('sha256', $request->getContent(), $this->webhookSecret);

        if (! hash_equals($expected, (string) $request->header(self::WEBHOOK_SECRET_HEADER))) {
            throw InvalidWebhookSignature::make();
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
        $state = $this->cache->get(self::CACHE_PREFIX.$assetId);
        if (is_array($state)) {
            $state['status'] = VideoAssetStatus::Errored->value;
            $this->cache->forever(self::CACHE_PREFIX.$assetId, $state);
        }
    }

    public function assetIdForUpload(string $uploadId): ?string
    {
        $assetId = $this->cache->get(self::CACHE_PREFIX.'upload:'.$uploadId);

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
        $request->headers->set(self::WEBHOOK_SECRET_HEADER, hash_hmac('sha256', $body, $this->webhookSecret));

        return $request;
    }
}
