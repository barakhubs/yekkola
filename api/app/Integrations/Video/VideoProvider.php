<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use App\Domain\Authoring\Enums\MediaKind;
use App\Integrations\InvalidWebhookSignature;
use Illuminate\Http\Request;

/**
 * Hosts lesson video and audio: direct uploads, processing, protected playback, offline licenses
 * (PRD-03, PRD-07). Mux in production; domain code depends only on this interface.
 */
interface VideoProvider
{
    public function name(): string;

    /** URL the professor's browser uploads the file to directly (media never touches our servers). */
    public function createDirectUpload(DirectUploadRequest $request): DirectUpload;

    public function getAsset(string $assetId): VideoAsset;

    public function deleteAsset(string $assetId): void;

    /**
     * Short-lived tokens for streaming one asset. Video gets a DRM license token; audio doesn't (no DRM on audio).
     */
    public function playbackTokens(string $playbackId, MediaKind $kind, int $ttlSeconds): PlaybackTokens;

    /** DRM token that issues a persistent (offline) license valid for $licenseSeconds. Video only. */
    public function offlineLicenseToken(string $playbackId, int $licenseSeconds, int $ttlSeconds): string;

    /**
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): VideoWebhookEvent;
}
