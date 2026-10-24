<?php

declare(strict_types=1);

namespace App\Domain\Protection\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Settings\ProtectionSettings;
use App\Domain\Protection\Exceptions\ProtectionException;
use App\Domain\Protection\Models\StreamSession;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a web stream counted as live (PRD-07 FR-05). A replaced stream gets `playback.stream_replaced` and
 * its player stops ("playing on another screen"). A stream that went quiet (tab asleep) resumes only if
 * it still fits the limit.
 */
final class RecordStreamHeartbeat
{
    public function __construct(private readonly ProtectionSettings $settings) {}

    public function handle(User $user, string $sessionId): StreamSession
    {
        return DB::transaction(function () use ($user, $sessionId): StreamSession {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $session = StreamSession::query()->where('user_id', $user->id)->findOrFail($sessionId);

            if (! $session->isEnded() && ! $this->isLive($session)) {
                $others = StreamSession::query()->live()->where('user_id', $user->id)->whereKeyNot($session->id)->count();
                if ($others >= max(1, $this->settings->concurrent_web_streams)) {
                    $session->forceFill(['ended_at' => now(), 'ended_reason' => StreamSession::REPLACED])->save();
                }
            }

            if ($session->isEnded()) {
                throw ProtectionException::streamReplaced();
            }

            $session->forceFill(['last_heartbeat_at' => now()])->save();

            return $session;
        });
    }

    private function isLive(StreamSession $session): bool
    {
        return $session->last_heartbeat_at->gte(now()->subSeconds((int) config('yekkola.protection.stream_stale_seconds')));
    }
}
