<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use App\Domain\Authoring\Models\MediaAsset;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Lesson media on Cloudflare R2 (owner decision 2026-11-09, arch §1.3):
 * - multipart uploads straight from the browser with pre-signed part URLs (the bucket's CORS must allow PUT
 *   from the studio and expose `ETag`, and GET/HEAD from the web origins for hls.js segment requests);
 * - FFmpeg on the queue worker (FfmpegTranscoder) writes encrypted HLS back under `media/{id}/hls/{run}/`;
 * - playlists are served by the API with segment URLs pre-signed for the playback window. Signing is local (no
 *   R2 call), and the signed playlist is cached for half that window since it isn't viewer-specific.
 */
final class R2VideoProvider implements VideoProvider
{
    private readonly S3Client $client;

    private readonly string $bucket;

    /**
     * @param  array<string, mixed>  $config  config('yekkola.media')
     */
    public function __construct(
        private readonly AwsS3V3Adapter $disk,
        private readonly FfmpegTranscoder $transcoder,
        private readonly Cache $cache,
        private readonly array $config,
    ) {
        $this->client = $disk->getClient();
        $this->bucket = (string) ($disk->getConfig()['bucket'] ?? '');
    }

    public function name(): string
    {
        return 'r2';
    }

    public function startUpload(MediaAsset $asset, int $sizeBytes, string $contentType): UploadSession
    {
        $key = "media/{$asset->id}/source";
        $result = $this->client->createMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key, 'ContentType' => $contentType]);
        $partSize = UploadSession::partSize($sizeBytes, (int) $this->config['part_size_mb'] * 1024 * 1024);

        return new UploadSession((string) $result['UploadId'], $key, $partSize, max(1, (int) ceil($sizeBytes / $partSize)));
    }

    public function uploadPartUrls(MediaAsset $asset, array $partNumbers, int $ttlSeconds): array
    {
        $urls = [];
        foreach ($partNumbers as $number) {
            $command = $this->client->getCommand('UploadPart', [
                'Bucket' => $this->bucket,
                'Key' => $asset->source_key,
                'UploadId' => $asset->provider_upload_id,
                'PartNumber' => $number,
            ]);
            $urls[$number] = (string) $this->client->createPresignedRequest($command, "+{$ttlSeconds} seconds")->getUri();
        }

        return $urls;
    }

    public function completeUpload(MediaAsset $asset, array $parts): int
    {
        usort($parts, fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        try {
            $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $asset->source_key,
                'UploadId' => $asset->provider_upload_id,
                'MultipartUpload' => ['Parts' => array_map(fn (array $p): array => ['PartNumber' => $p['number'], 'ETag' => $p['etag']], $parts)],
            ]);

            return (int) $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $asset->source_key])['ContentLength'];
        } catch (S3Exception $e) {
            throw new UploadIncomplete((string) $e->getAwsErrorCode(), previous: $e);
        }
    }

    public function abortUpload(MediaAsset $asset): void
    {
        try {
            $this->client->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $asset->source_key, 'UploadId' => $asset->provider_upload_id]);
        } catch (S3Exception) {
            // already completed or gone
        }
    }

    public function process(MediaAsset $asset, string $watermarkText): ProcessedMedia
    {
        $work = sys_get_temp_dir().'/yekkola-media-'.Str::ulid();
        $out = $work.'/out';
        mkdir($out, 0700, true);
        $prefix = "media/{$asset->id}/hls/".strtolower((string) Str::ulid());

        try {
            $source = $work.'/source';
            $stream = $this->disk->readStream((string) $asset->source_key);
            if (! is_resource($stream)) {
                throw new MediaProcessingFailed('Source file missing.');
            }
            $local = fopen($source, 'wb');
            stream_copy_to_stream($stream, $local ?: throw new MediaProcessingFailed('No temp space.'));
            fclose($local);
            fclose($stream);

            $key = random_bytes(16);
            $result = $this->transcoder->transcode($source, $out, $asset->kind, $key, $watermarkText);

            foreach ((new Finder)->files()->in($out) as $file) {
                $handle = fopen($file->getPathname(), 'rb');
                $this->disk->writeStream($prefix.'/'.str_replace('\\', '/', $file->getRelativePathname()), $handle, [
                    'ContentType' => match ($file->getExtension()) {
                        'm3u8' => 'application/vnd.apple.mpegurl',
                        'ts' => 'video/mp2t',
                        'jpg' => 'image/jpeg',
                        default => 'application/octet-stream',
                    },
                ]);
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            return new ProcessedMedia($result['renditions'], $result['duration'], $key, $prefix, $result['poster']);
        } finally {
            (new Filesystem)->deleteDirectory($work);
        }
    }

    public function mediaPlaylist(MediaAsset $asset, Rendition $rendition, string $keyUri, int $ttlSeconds): string
    {
        $path = "{$asset->output_prefix}/{$rendition->name}";
        $signed = $this->cache->remember(
            "media:{$asset->id}:{$rendition->name}:signed:{$ttlSeconds}",
            max(60, intdiv($ttlSeconds, 2)),
            function () use ($asset, $path, $rendition, $ttlSeconds): string {
                $raw = $this->cache->rememberForever(
                    "media:{$asset->id}:{$rendition->name}:raw",
                    fn (): string => (string) $this->disk->get("{$path}/index.m3u8"),
                );

                return HlsPlaylist::signSegments($raw, fn (string $segment): string => $this->disk->temporaryUrl("{$path}/{$segment}", now()->addSeconds($ttlSeconds)));
            },
        );

        return HlsPlaylist::withKey((string) $signed, $keyUri);
    }

    public function posterUrl(MediaAsset $asset, int $ttlSeconds): ?string
    {
        return $asset->has_poster ? $this->disk->temporaryUrl("{$asset->output_prefix}/poster.jpg", now()->addSeconds($ttlSeconds)) : null;
    }

    public function deleteAsset(MediaAsset $asset): void
    {
        $this->abortUpload($asset);
        $this->disk->deleteDirectory("media/{$asset->id}");
        foreach ($asset->renditionList() as $rendition) {
            $this->cache->forget("media:{$asset->id}:{$rendition->name}:raw");
        }
    }
}
