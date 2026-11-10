<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use App\Domain\Authoring\Enums\MediaKind;
use App\Domain\Authoring\Models\MediaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Fake media host for local, staging, and tests. Refused in production.
 *
 * Part URLs point at our own fake endpoint (PUT /api/v1/_fake/video-uploads/{upload}/parts/{n}), so the real
 * studio upload flow works without R2: each part answers with the ETag `"fake-{n}"`, nothing is stored, and
 * "processing" returns a fixed 20-minute result at once. failProcessing() simulates a file FFmpeg can't read.
 * Playlists and segment URLs are synthetic.
 */
final class FakeVideoProvider implements VideoProvider
{
    private const PREFIX = 'fake-video:';

    private const PART_BYTES = 1024 * 1024;

    public function __construct(private readonly Cache $cache) {}

    public function name(): string
    {
        return 'fake';
    }

    /**
     * 1 MB parts: they go through the API's own fake endpoint, so they must fit PHP's and the web server's request
     * size limits (R2 parts go straight to R2 and stay at 10 MB).
     */
    public function partSizeBytes(int $sizeBytes): int
    {
        return UploadSession::partSize($sizeBytes, self::PART_BYTES, self::PART_BYTES);
    }

    public function startUpload(MediaAsset $asset, int $sizeBytes, string $contentType): UploadSession
    {
        $partSize = $this->partSizeBytes($sizeBytes);
        $session = new UploadSession('fake-upload-'.Str::ulid(), "media/{$asset->id}/source", $partSize, max(1, (int) ceil($sizeBytes / $partSize)));
        $this->cache->put(self::PREFIX.'upload:'.$session->uploadId, $session->partCount, now()->addDays(7));

        return $session;
    }

    public function uploadPartUrls(MediaAsset $asset, array $partNumbers, int $ttlSeconds): array
    {
        $base = rtrim((string) config('app.url'), '/').'/api/v1/_fake/video-uploads/'.$asset->provider_upload_id.'/parts/';
        $urls = [];
        foreach ($partNumbers as $number) {
            $urls[$number] = $base.$number;
        }

        return $urls;
    }

    /** The ETag the fake part endpoint answers with, or null when no such upload is open. */
    public function receivePart(string $uploadId, int $number): ?string
    {
        $count = $this->cache->get(self::PREFIX.'upload:'.$uploadId);

        return is_int($count) && $number >= 1 && $number <= $count ? "\"fake-{$number}\"" : null;
    }

    public function completeUpload(MediaAsset $asset, array $parts): int
    {
        $count = $this->cache->get(self::PREFIX.'upload:'.$asset->provider_upload_id);
        if (! is_int($count)) {
            throw new UploadIncomplete('No such upload.');
        }

        $received = [];
        foreach ($parts as $part) {
            if (trim((string) preg_replace('/^W\//', '', $part['etag']), '"') === "fake-{$part['number']}") {
                $received[$part['number']] = true;
            }
        }
        if (count($received) !== $count || array_diff(range(1, $count), array_keys($received)) !== []) {
            throw new UploadIncomplete('Missing parts.');
        }

        $this->cache->forget(self::PREFIX.'upload:'.$asset->provider_upload_id);

        return (int) ($this->cache->pull(self::PREFIX.'size:'.$asset->id) ?? $asset->size_bytes);
    }

    /** The next completeUpload() of this asset reports `$bytes` stored (a client that sent more than it said). */
    public function storeSize(string $assetId, int $bytes): void
    {
        $this->cache->put(self::PREFIX.'size:'.$assetId, $bytes, now()->addDay());
    }

    public function abortUpload(MediaAsset $asset): void
    {
        $this->cache->forget(self::PREFIX.'upload:'.$asset->provider_upload_id);
    }

    public function process(MediaAsset $asset, string $watermarkText): ProcessedMedia
    {
        if ($this->cache->pull(self::PREFIX.'fail:'.$asset->id) !== null) {
            throw new MediaProcessingFailed('Simulated processing error.');
        }

        $renditions = $asset->kind === MediaKind::Video
            ? [
                new Rendition('720p', 2_000_000, 270_000_000, 1280, 720),
                new Rendition('480p', 1_000_000, 135_000_000, 854, 480),
                new Rendition('360p', 600_000, 80_000_000, 640, 360),
            ]
            : [new Rendition('audio', 70_000, 10_000_000)];

        return new ProcessedMedia($renditions, 1_200, random_bytes(16), "media/{$asset->id}/hls/fake", $asset->kind === MediaKind::Video);
    }

    /** The next process() of this asset fails. */
    public function failProcessing(string $assetId): void
    {
        $this->cache->put(self::PREFIX.'fail:'.$assetId, true, now()->addDay());
    }

    public function mediaPlaylist(MediaAsset $asset, Rendition $rendition, string $keyUri, int $ttlSeconds): string
    {
        $expires = CarbonImmutable::now()->addSeconds($ttlSeconds)->getTimestamp();
        $raw = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n#EXT-X-PLAYLIST-TYPE:VOD\n"
            .'#EXT-X-KEY:METHOD=AES-128,URI="'.HlsPlaylist::KEY_PLACEHOLDER."\"\n"
            ."#EXTINF:6.0,\nseg_00000.ts\n#EXTINF:6.0,\nseg_00001.ts\n#EXT-X-ENDLIST\n";

        return HlsPlaylist::withKey(
            HlsPlaylist::signSegments($raw, fn (string $segment): string => "https://media.fake.test/{$asset->id}/{$rendition->name}/{$segment}?expires={$expires}"),
            $keyUri,
        );
    }

    public function posterUrl(MediaAsset $asset, int $ttlSeconds): ?string
    {
        return $asset->has_poster ? "https://media.fake.test/{$asset->id}/poster.jpg?expires=".CarbonImmutable::now()->addSeconds($ttlSeconds)->getTimestamp() : null;
    }

    public function deleteAsset(MediaAsset $asset): void {}
}
