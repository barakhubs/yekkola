<?php

declare(strict_types=1);

namespace App\Domain\Protection\Actions;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\CourseAccess;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Platform\Settings\ProtectionSettings;
use App\Domain\Protection\Exceptions\ProtectionException;
use App\Domain\Protection\Models\StreamSession;
use App\Domain\Protection\PlaybackGrant;
use App\Domain\Protection\ViewerMark;
use App\Integrations\Video\VideoProvider;
use Illuminate\Support\Facades\DB;

/**
 * Issues short-lived playback (and, for video, DRM) tokens for one lesson (PRD-07 FR-02). Enrolled students
 * get any lesson; anyone — signed in or not — gets preview lessons of listed courses (PRD-04 FR-06). Web
 * streams of signed-in students count toward the concurrent-stream limit (FR-05): over the limit, the
 * oldest live stream is ended and its player stops at its next heartbeat. Mobile apps are limited by
 * registered devices instead.
 */
final class IssuePlayback
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly VideoProvider $video,
        private readonly ProtectionSettings $settings,
    ) {}

    public function handle(?User $user, string $lessonUid, bool $isWebStream): PlaybackGrant
    {
        [$lesson, $course] = $this->access->liveLesson($lessonUid);
        if (! in_array($lesson->type, [LessonType::Video, LessonType::Audio], true)) {
            throw ProtectionException::notPlayable();
        }

        $enrolled = $user !== null && $this->access->activeEnrollment($user, $course->id) !== null;
        if (! $enrolled && ! $this->access->previewAllowed($lesson, $course)) {
            throw $user === null ? ProtectionException::previewOnly() : LearningException::enrollmentRequired();
        }

        $asset = MediaAsset::query()->find($lesson->media_asset_id);
        if ($asset === null || ! $asset->isReady() || $asset->playback_id === null) {
            throw ProtectionException::mediaNotReady();
        }

        $tokens = $this->video->playbackTokens($asset->playback_id, $asset->kind, $this->settings->playback_token_ttl_minutes * 60);
        $session = $user !== null && $isWebStream ? $this->startStream($user, $lessonUid, $asset) : null;

        return new PlaybackGrant(
            lessonUid: $lessonUid,
            kind: $asset->kind,
            playbackId: $asset->playback_id,
            tokens: $tokens,
            watermark: $user !== null ? ViewerMark::overlay($user) : null,
            streamSessionId: $session?->id,
            isPreview: ! $enrolled,
        );
    }

    /** Newest stream wins: end the oldest live ones until this one fits the limit. */
    private function startStream(User $user, string $lessonUid, MediaAsset $asset): StreamSession
    {
        return DB::transaction(function () use ($user, $lessonUid, $asset): StreamSession {
            // One start at a time per account, so two tabs can't both slip under the limit.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $live = StreamSession::query()->live()->where('user_id', $user->id)->orderBy('created_at')->orderBy('id')->get();
            $excess = $live->count() - max(1, $this->settings->concurrent_web_streams) + 1;
            if ($excess > 0) {
                StreamSession::query()->whereIn('id', $live->take($excess)->pluck('id'))
                    ->update(['ended_at' => now(), 'ended_reason' => StreamSession::REPLACED, 'updated_at' => now()]);
            }

            return StreamSession::query()->create([
                'user_id' => $user->id,
                'lesson_uid' => $lessonUid,
                'media_asset_id' => $asset->id,
                'last_heartbeat_at' => now(),
            ]);
        });
    }
}
