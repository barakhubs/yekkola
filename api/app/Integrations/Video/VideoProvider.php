<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use App\Domain\Authoring\Models\MediaAsset;

/**
 * Stores, processes and serves lesson video and audio (PRD-03 FR-04/05, PRD-07, arch §1.3). Cloudflare R2 +
 * FFmpeg in production (`R2VideoProvider`); domain code depends only on this interface.
 *
 * - Upload: the professor's browser sends the file straight to storage in parts, with pre-signed part URLs
 *   (media never touches our servers), then the studio confirms the upload.
 * - Processing (queue worker): HLS renditions (720p cap + lower tiers; audio-only HLS for audio), AES-128
 *   with one key per asset, burned-in watermark. The key comes back to us and never sits next to the segments.
 * - Playback: we serve the playlists. A media playlist points at short-lived signed segment URLs and at our
 *   key endpoint, which only hands the key to users with access.
 */
interface VideoProvider
{
    public function name(): string;

    /** Bytes per upload part for a file of `$sizeBytes` (the browser cuts the file this way). */
    public function partSizeBytes(int $sizeBytes): int;

    /** Open a multipart upload of `$sizeBytes` for the asset. */
    public function startUpload(MediaAsset $asset, int $sizeBytes, string $contentType): UploadSession;

    /**
     * Pre-signed URLs the browser PUTs each part to (it reads each response's `ETag`).
     *
     * @param  list<int>  $partNumbers  1-based
     * @return array<int, string> part number => URL
     */
    public function uploadPartUrls(MediaAsset $asset, array $partNumbers, int $ttlSeconds): array;

    /**
     * Assemble the uploaded parts. Returns the stored object's size in bytes.
     *
     * @param  list<array{number: int, etag: string}>  $parts
     *
     * @throws UploadIncomplete
     */
    public function completeUpload(MediaAsset $asset, array $parts): int;

    /** Drop an unfinished upload and its parts. */
    public function abortUpload(MediaAsset $asset): void;

    /**
     * Transcode, encrypt and store the HLS output (runs on a queue worker; can take a while).
     *
     * @throws MediaProcessingFailed
     */
    public function process(MediaAsset $asset, string $watermarkText): ProcessedMedia;

    /** One rendition's playlist: segment URIs signed for `$ttlSeconds`, the key at `$keyUri`. */
    public function mediaPlaylist(MediaAsset $asset, Rendition $rendition, string $keyUri, int $ttlSeconds): string;

    /** Short-lived URL of the video's poster frame; null for audio. */
    public function posterUrl(MediaAsset $asset, int $ttlSeconds): ?string;

    /** Delete the source and every processed file. */
    public function deleteAsset(MediaAsset $asset): void;
}
