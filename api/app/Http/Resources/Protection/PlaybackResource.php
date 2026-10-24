<?php

declare(strict_types=1);

namespace App\Http\Resources\Protection;

use App\Domain\Protection\PlaybackGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything a player needs for one lesson (PRD-07 FR-02): the playback ID and signed tokens (Mux Player
 * `tokens.*`), the viewer overlay text, and the stream session to heartbeat. Never a plain media URL.
 *
 * @property PlaybackGrant $resource
 */
final class PlaybackResource extends JsonResource
{
    public function __construct(PlaybackGrant $grant)
    {
        parent::__construct($grant);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $grant = $this->resource;

        return [
            'lesson_uid' => $grant->lessonUid,
            'kind' => $grant->kind->value,
            'playback_id' => $grant->playbackId,
            'tokens' => [
                'playback' => $grant->tokens->playbackToken,
                'drm' => $grant->tokens->drmToken,
                'thumbnail' => $grant->tokens->thumbnailToken,
                'storyboard' => $grant->tokens->storyboardToken,
            ],
            'expires_at' => $grant->tokens->expiresAt->toIso8601String(),
            'watermark' => $grant->watermark === null ? null : ['text' => $grant->watermark],
            'stream' => $grant->streamSessionId === null ? null : [
                'session_id' => $grant->streamSessionId,
                'heartbeat_seconds' => (int) config('yekkola.protection.stream_heartbeat_seconds'),
            ],
            'is_preview' => $grant->isPreview,
        ];
    }
}
