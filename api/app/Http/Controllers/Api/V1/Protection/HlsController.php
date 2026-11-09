<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Protection;

use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Settings\ProtectionSettings;
use App\Domain\Protection\Actions\AuthorizeMedia;
use App\Http\Controllers\Controller;
use App\Integrations\Video\HlsPlaylist;
use App\Integrations\Video\Rendition;
use App\Integrations\Video\VideoProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Encrypted HLS for one lesson (PRD-07 FR-02, arch §1.3 D). The player loads `playlist_url` from
 * `POST /lessons/{uid}/playback`; every request here repeats the access check (AuthorizeMedia). Works signed
 * in (cookie or token) or as a guest for previews. Browsers must send credentials to these URLs and none to
 * the signed segment URLs.
 */
final class HlsController extends Controller
{
    private const PLAYLIST_TYPE = 'application/vnd.apple.mpegurl';

    public function __construct(
        private readonly AuthorizeMedia $authorize,
        private readonly ProtectionSettings $settings,
    ) {}

    /** The renditions available for the lesson (adaptive bitrate). `session`: the web stream session id. */
    public function master(Request $request, string $lesson): Response
    {
        [, $asset] = $this->authorize->handle($this->user($request), $lesson, $this->session($request));
        $query = $this->query($request);

        $body = HlsPlaylist::master(
            $asset->renditionList(),
            fn (Rendition $r): string => route('lessons.hls.media', ['lesson' => $lesson, 'rendition' => $r->name, ...$query]),
        );

        return $this->playlist($body);
    }

    /** One rendition: signed segment URLs and our key URI. */
    public function media(Request $request, string $lesson, string $rendition, VideoProvider $videos): Response
    {
        [, $asset] = $this->authorize->handle($this->user($request), $lesson, $this->session($request));
        $chosen = $asset->rendition($rendition);
        abort_if($chosen === null, 404);

        $keyUri = route('lessons.hls.key', ['lesson' => $lesson, ...$this->query($request)]);

        return $this->playlist($videos->mediaPlaylist($asset, $chosen, $keyUri, $this->segmentTtl($asset)));
    }

    /** The AES-128 key (16 raw bytes), only after the access check. Never cached by the browser. */
    public function key(Request $request, string $lesson): Response
    {
        [, $asset] = $this->authorize->handle($this->user($request), $lesson, $this->session($request));

        return response((string) $asset->key(), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Segment URLs must outlast watching the whole lesson in one go (hls.js loads the playlist once): the
     * playback window plus twice the lesson's length, at most 6 h. They only fetch encrypted bytes; a player
     * that already holds the key and playlist can finish the lesson within that window (accepted trade-off of
     * encrypted HLS without DRM, owner decision 2026-11-09).
     */
    private function segmentTtl(MediaAsset $asset): int
    {
        return min(21_600, $this->settings->playback_token_ttl_minutes * 60 + 2 * (int) $asset->duration_seconds);
    }

    private function playlist(string $body): Response
    {
        return response($body, 200, ['Content-Type' => self::PLAYLIST_TYPE, 'Cache-Control' => 'no-store, private']);
    }

    private function user(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User ? $user : null;
    }

    private function session(Request $request): ?string
    {
        $session = $request->query('session');

        return is_string($session) && $session !== '' ? $session : null;
    }

    /**
     * @return array<string, string>
     */
    private function query(Request $request): array
    {
        $session = $this->session($request);

        return $session === null ? [] : ['session' => $session];
    }
}
