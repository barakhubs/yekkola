<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Protection;

use App\Domain\Identity\Models\User;
use App\Domain\Protection\Actions\IssuePlayback;
use App\Domain\Protection\Actions\RecordStreamHeartbeat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Protection\HeartbeatRequest;
use App\Http\Resources\Protection\PlaybackResource;
use Illuminate\Http\Request;

/** Playback tokens for video and audio lessons, and web stream heartbeats (PRD-07 FR-02, FR-04, FR-05). */
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
