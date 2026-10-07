<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use App\Domain\Authoring\Enums\MediaKind;
use App\Integrations\InvalidWebhookPayload;
use App\Integrations\InvalidWebhookSignature;
use Illuminate\Http\Request;

/**
 * Hosts lesson video and audio: direct uploads, processing, protected playback, offline access
 * (PRD-03, PRD-07, docs/research/mux.md). Mux in production; domain code depends only on this interface.
 *
 * - Video: DRM (plus quality, max 720p). Offline via persistent DRM licenses (offlineLicenseToken).
 * - Audio: signed playback only (no DRM on audio). Drivers request a downloadable audio rendition at upload;
 *   offline audio is fetched with audioDownloadUrl() and encrypted at rest by the app.
 * - Webhooks: verify the signature (with the provider's replay window), then re-fetch the asset.
 */
interface VideoProvider
{
    public function name(): string;

    /** URL the professor's browser uploads the file to directly (media never touches our servers). */
    public function createDirectUpload(DirectUploadRequest $request): DirectUpload;

    /**
     * @throws VideoAssetNotFound
     */
    public function getAsset(string $assetId): VideoAsset;

    public function deleteAsset(string $assetId): void;

    /** Short-lived tokens for streaming one asset. Video includes DRM/thumbnail/storyboard tokens; audio doesn't. */
    public function playbackTokens(string $playbackId, MediaKind $kind, int $ttlSeconds): PlaybackTokens;

    /** DRM token that issues a persistent (offline) license valid for $licenseSeconds. Video only. */
    public function offlineLicenseToken(string $playbackId, int $licenseSeconds, int $ttlSeconds): string;

    /** Short-lived signed URL to download an audio lesson's file for offline use. Audio only. */
    public function audioDownloadUrl(string $playbackId, int $ttlSeconds): string;

    /**
     * @throws InvalidWebhookSignature
     * @throws InvalidWebhookPayload
     */
    public function parseWebhook(Request $request): VideoWebhookEvent;
}
