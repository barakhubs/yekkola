<?php

declare(strict_types=1);

namespace App\Domain\Protection\Actions;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Settings\ProtectionSettings;
use App\Domain\Protection\Models\OfflineLicense;
use App\Domain\Protection\RegisteredDevice;
use Carbon\CarbonImmutable;

/**
 * Entitlement sync for the app (PRD-07 FR-10, FR-15, BR-02, BR-04): every offline license this device holds,
 * with what to do with the download. The API decides; the app only follows:
 *
 * - `delete` — the license was revoked (device removed, account deleted), access is gone (refund,
 *   enrollment ended, takedown, lesson no longer in the live version), or the lesson has new media.
 * - `renew` — access is fine and the license has expired or has less than half its duration left.
 * - `keep` — nothing to do.
 *
 * Read-only: nothing changes until the app acts (renewal goes through `POST /lessons/{uid}/offline-license`).
 * A download whose license is missing from the list is deleted by the app too.
 */
final class ListEntitlements
{
    public const KEEP = 'keep';

    public const RENEW = 'renew';

    public const DELETE = 'delete';

    public function __construct(
        private readonly RegisteredDevice $devices,
        private readonly ProtectionSettings $settings,
    ) {}

    /**
     * @return list<array{license: OfflineLicense, action: string, reason: string|null, current_media_id: string|null}>
     */
    public function handle(User $user, ?string $installId): array
    {
        $device = $this->devices->resolve($user, $installId);
        $licenses = OfflineLicense::query()->where('device_id', $device->id)->orderBy('lesson_uid')->get();
        if ($licenses->isEmpty()) {
            return [];
        }

        $lessons = $this->accessibleLessons($user, $licenses->pluck('lesson_uid')->unique()->values()->all());
        $now = CarbonImmutable::now();
        // Renew once half the license duration is used up, so a phone that comes online now and then never
        // reaches the expiry; the setting in force today decides.
        $renewWithin = intdiv($this->settings->offline_license_days * 86_400, 2);

        return $licenses->map(function (OfflineLicense $license) use ($lessons, $now, $renewWithin): array {
            $lesson = $lessons[$license->lesson_uid] ?? null;

            [$action, $reason] = match (true) {
                $license->revoked_at !== null => [self::DELETE, 'revoked'],
                $lesson === null => [self::DELETE, 'no_access'],
                $lesson->media_asset_id !== $license->media_asset_id => [self::DELETE, 'media_changed'],
                $license->expires_at->lessThanOrEqualTo($now) => [self::RENEW, 'expired'],
                $now->diffInSeconds($license->expires_at) < $renewWithin => [self::RENEW, 'expiring'],
                default => [self::KEEP, null],
            };

            return [
                'license' => $license,
                'action' => $action,
                'reason' => $reason,
                'current_media_id' => $lesson?->media_asset_id,
            ];
        })->values()->all();
    }

    /**
     * Video/audio lessons of the live versions of courses the student may still use (active enrollment, not
     * taken down), by uid.
     *
     * @param  list<string>  $uids
     * @return array<string, Lesson>
     */
    private function accessibleLessons(User $user, array $uids): array
    {
        $courseIds = Enrollment::query()->active()->where('user_id', $user->id)->pluck('course_id');
        $liveVersions = Course::query()->whereIn('id', $courseIds)->whereNull('taken_down_at')
            ->whereNotNull('live_version_id')->pluck('live_version_id');

        return Lesson::query()->whereIn('uid', $uids)->whereIn('course_version_id', $liveVersions)
            ->whereIn('type', [LessonType::Video, LessonType::Audio])->get()
            ->keyBy('uid')->all();
    }
}
