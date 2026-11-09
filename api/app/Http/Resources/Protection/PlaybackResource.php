<?php

declare(strict_types=1);

namespace App\Http\Resources\Protection;

use App\Domain\Protection\PlaybackGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything a player needs for one lesson (PRD-07 FR-02): the encrypted-HLS master playlist on our API (load it
 * with credentials; segments are signed URLs, loaded without), a poster, the viewer overlay text, and the
 * stream session to heartbeat. Never a plain media URL.
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
            'playlist_url' => $grant->playlistUrl,
            'poster_url' => $grant->posterUrl,
            'expires_at' => $grant->expiresAt->toIso8601String(),
            'watermark' => $grant->watermark === null ? null : ['text' => $grant->watermark],
            'stream' => $grant->streamSessionId === null ? null : [
                'session_id' => $grant->streamSessionId,
                'heartbeat_seconds' => (int) config('yekkola.protection.stream_heartbeat_seconds'),
            ],
            'is_preview' => $grant->isPreview,
        ];
    }
}
