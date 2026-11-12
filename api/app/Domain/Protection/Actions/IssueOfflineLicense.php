<?php

declare(strict_types=1);

namespace App\Domain\Protection\Actions;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\CourseAccess;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Platform\Settings\ProtectionSettings;
use App\Domain\Protection\Exceptions\ProtectionException;
use App\Domain\Protection\Models\OfflineLicense;
use App\Domain\Protection\OfflineGrant;
use App\Domain\Protection\RegisteredDevice;
use App\Domain\Protection\ViewerMark;
use App\Integrations\Video\Rendition;
use App\Integrations\Video\VideoProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Grants a registered device an offline download of one lesson (PRD-07 FR-07, arch §1.3 E): the chosen
 * rendition's playlist (our lowest by default, FR-08), the AES key, and an expiry from the offline-license
 * setting. Asking again renews the same license (new expiry, fresh segment URLs) — that is also how the app
 * resumes a download whose segment URLs ran out.
 *
 * Only enrolled students, from the mobile app with an active registered device whose install ID matches the
 * request's `X-Device-Id`: the key is meant for that one phone. Web sessions get no offline licenses.
 */
final class IssueOfflineLicense
{
    /** Segment URLs of a download: long enough for a slow connection; the app renews to resume after. */
    private const SEGMENT_TTL_SECONDS = 21_600;

    public function __construct(
        private readonly CourseAccess $access,
        private readonly VideoProvider $video,
        private readonly ProtectionSettings $settings,
        private readonly RegisteredDevice $devices,
    ) {}

    public function handle(User $user, ?string $installId, string $lessonUid, ?string $renditionName): OfflineGrant
    {
        $device = $this->devices->resolve($user, $installId);

        [$lesson, $course] = $this->access->liveLesson($lessonUid);
        if (! in_array($lesson->type, [LessonType::Video, LessonType::Audio], true)) {
            throw ProtectionException::notPlayable();
        }
        if ($this->access->activeEnrollment($user, $course->id) === null) {
            throw LearningException::enrollmentRequired();
        }

        $asset = MediaAsset::query()->find($lesson->media_asset_id);
        $key = $asset?->key();
        if ($asset === null || ! $asset->isReady() || $key === null) {
            throw ProtectionException::mediaNotReady();
        }

        $renditions = $asset->renditionList();
        $rendition = $renditionName === null ? $this->lowest($renditions) : $asset->rendition($renditionName);
        if ($rendition === null) {
            throw ProtectionException::renditionUnavailable();
        }

        $now = CarbonImmutable::now();
        try {
            $license = $this->save($user, $device, $lessonUid, $asset, $rendition, $now);
        } catch (UniqueConstraintViolationException) {
            // Two first requests at once (double tap, client retry): the other one inserted; update it.
            $license = $this->save($user, $device, $lessonUid, $asset, $rendition, $now);
        }

        return new OfflineGrant(
            license: $license,
            kind: $asset->kind,
            rendition: $rendition,
            renditions: $renditions,
            playlist: $this->video->mediaPlaylist($asset, $rendition, route('lessons.hls.key', ['lesson' => $lessonUid]), self::SEGMENT_TTL_SECONDS),
            key: $key,
            // Signed playlists are cached for half their lifetime, so only this much is guaranteed.
            segmentsExpireAt: $now->addSeconds(intdiv(self::SEGMENT_TTL_SECONDS, 2)),
            watermark: ViewerMark::overlay($user),
        );
    }

    /** One license per device and lesson, renewed in place. */
    private function save(User $user, Device $device, string $lessonUid, MediaAsset $asset, Rendition $rendition, CarbonImmutable $now): OfflineLicense
    {
        return DB::transaction(function () use ($user, $device, $lessonUid, $asset, $rendition, $now): OfflineLicense {
            $license = OfflineLicense::query()->where('device_id', $device->id)->where('lesson_uid', $lessonUid)
                ->lockForUpdate()->first() ?? new OfflineLicense(['device_id' => $device->id, 'lesson_uid' => $lessonUid]);

            $license->fill([
                'user_id' => $user->id,
                'media_asset_id' => $asset->id,
                'rendition' => $rendition->name,
                'issued_at' => $now,
                // Going forward only: a settings change applies at the next renewal.
                'expires_at' => $now->addDays($this->settings->offline_license_days),
                'revoked_at' => null,
            ])->save();

            return $license;
        });
    }

    /**
     * Our lowest video rendition by bandwidth (or the audio rendition).
     *
     * @param  list<Rendition>  $renditions
     */
    private function lowest(array $renditions): ?Rendition
    {
        usort($renditions, fn (Rendition $a, Rendition $b) => $a->bandwidth <=> $b->bandwidth);

        return $renditions[0] ?? null;
    }
}
