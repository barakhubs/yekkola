<?php

declare(strict_types=1);

namespace App\Domain\Protection\Actions;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\CourseAccess;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Protection\Exceptions\ProtectionException;
use App\Domain\Protection\Models\StreamSession;

/**
 * Who may load a lesson's playlists and AES key (PRD-07 FR-02, FR-05). Checked on every playlist and key
 * request, not only when playback starts, so ending a stream, a refund or a takedown stops new loads at once.
 * A player that already loaded the playlist and key can keep playing until its segment URLs expire (the accepted
 * limit of encrypted HLS without DRM).
 *
 * - Preview lessons of listed courses: anyone.
 * - Enrolled students in a browser: only with their live stream session (`session`), so a replaced or quiet
 *   stream gets no new playlists or keys.
 * - Enrolled students in the mobile app (device token): no stream session; registered devices limit them.
 */
final class AuthorizeMedia
{
    public function __construct(private readonly CourseAccess $access) {}

    /**
     * @return array{0: Lesson, 1: MediaAsset}
     */
    public function handle(?User $user, string $lessonUid, ?string $sessionId): array
    {
        [$lesson, $course] = $this->access->liveLesson($lessonUid);
        if (! in_array($lesson->type, [LessonType::Video, LessonType::Audio], true)) {
            throw ProtectionException::notPlayable();
        }

        $user = $user !== null && $user->isActive() ? $user : null;
        $preview = $this->access->previewAllowed($lesson, $course);
        $enrolled = $user !== null && $this->access->activeEnrollment($user, $course->id) !== null;

        if ($sessionId !== null) {
            $live = $user !== null && StreamSession::query()->live()->whereKey($sessionId)
                ->where('user_id', $user->id)->where('lesson_uid', $lessonUid)->exists();
            if (! $live) {
                throw ProtectionException::streamRequired();
            }
        }

        if (! $preview) {
            if (! $enrolled) {
                throw $user === null ? ProtectionException::previewOnly() : LearningException::enrollmentRequired();
            }
            if ($sessionId === null && $user->currentDeviceId() === null) {
                throw ProtectionException::streamRequired();
            }
        }

        $asset = MediaAsset::query()->find($lesson->media_asset_id);
        if ($asset === null || ! $asset->isReady() || $asset->key() === null) {
            throw ProtectionException::mediaNotReady();
        }

        return [$lesson, $asset];
    }
}
