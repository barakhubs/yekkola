<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Protection;

use App\Domain\Identity\Models\User;
use App\Domain\Protection\Actions\IssueOfflineLicense;
use App\Domain\Protection\Actions\IssuePlayback;
use App\Domain\Protection\Actions\ListEntitlements;
use App\Domain\Protection\Actions\RecordStreamHeartbeat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Protection\HeartbeatRequest;
use App\Http\Requests\Protection\OfflineLicenseRequest;
use App\Http\Resources\Protection\OfflineLicenseResource;
use App\Http\Resources\Protection\PlaybackResource;
use App\Http\Support\AuthContext;
use Illuminate\Http\Request;

/** Playback tokens for video and audio lessons, web stream heartbeats, offline licenses (PRD-07 FR-02, FR-04, FR-05, FR-07). */
final class PlaybackController extends Controller
{
    /**
     * Tokens for an enrolled lesson (or a preview). Web players get a stream session to heartbeat; mobile apps
     * (device token) don't count toward the web stream limit.
     */
    public function store(Request $request, string $lesson, IssuePlayback $issue): PlaybackResource
    {
        $user = $this->user($request);

        return new PlaybackResource($issue->handle($user, $lesson, isWebStream: $user->currentDeviceId() === null));
    }

    /**
     * Offline download for this registered device: the rendition's playlist, the AES key and the license
     * expiry. Asking again renews the license and refreshes the segment URLs (to resume a download).
     * `offline.device_required` (403) outside the app or from another install than the token's device.
     */
    public function offline(OfflineLicenseRequest $request, string $lesson, IssueOfflineLicense $issue): OfflineLicenseResource
    {
        $rendition = $request->input('rendition');

        return new OfflineLicenseResource($issue->handle(
            $this->user($request),
            AuthContext::client($request)->deviceId,
            $lesson,
            is_string($rendition) ? $rendition : null,
        ));
    }

    /**
     * Entitlement sync for this registered device: every offline license it holds and what to do with the
     * download — `keep`, `renew` (ask for the offline license again) or `delete` (`reason`: revoked, no_access, media_unavailable,
     * media_changed; renew: expired, expiring). Downloads whose license is missing from the list are deleted too.
     *
     * @return array{data: array{checked_at: string, licenses: list<array{license_id: string, lesson_uid: string, media_id: string, current_media_id: string|null, rendition: string, expires_at: string, action: string, reason: string|null}>}}
     */
    public function entitlements(Request $request, ListEntitlements $entitlements): array
    {
        $items = $entitlements->handle($this->user($request), AuthContext::client($request)->deviceId);

        return ['data' => [
            'checked_at' => now()->toIso8601String(),
            'licenses' => array_map(fn (array $item): array => [
                'license_id' => $item['license']->id,
                'lesson_uid' => $item['license']->lesson_uid,
                'media_id' => $item['license']->media_asset_id,
                'current_media_id' => $item['current_media_id'],
                'rendition' => $item['license']->rendition,
                'expires_at' => $item['license']->expires_at->toIso8601String(),
                'action' => $item['action'],
                'reason' => $item['reason'],
            ], $items),
        ]];
    }

    /**
     * Tokens for a preview lesson of a listed course, without signing in (PRD-04 FR-06). No watermark name.
     */
    public function preview(string $lesson, IssuePlayback $issue): PlaybackResource
    {
        return new PlaybackResource($issue->handle(null, $lesson, isWebStream: false));
    }

    /**
     * Keeps a web stream live. `playback.stream_replaced` (409) means another screen took over: stop playing.
     *
     * @return array{data: array{session_id: string, active: true, heartbeat_seconds: int}}
     */
    public function heartbeat(HeartbeatRequest $request, string $lesson, RecordStreamHeartbeat $heartbeat): array
    {
        $session = $heartbeat->handle($this->user($request), $lesson, $request->string('session_id')->toString());

        return ['data' => [
            'session_id' => $session->id,
            'active' => true,
            'heartbeat_seconds' => (int) config('yekkola.protection.stream_heartbeat_seconds'),
        ]];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
