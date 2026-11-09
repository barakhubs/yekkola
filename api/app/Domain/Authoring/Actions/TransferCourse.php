<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Commerce\Enums\BundleStatus;
use App\Domain\Commerce\Enums\DiscountFunder;
use App\Domain\Commerce\Models\Bundle;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Professors\Enums\ProfessorStatus;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Database\ConnectionInterface;

/**
 * A super admin moves a course to another professor (PRD-09 FR-08), e.g. a co-author leaving or an account
 * replaced. Going forward only:
 * - future sales earn the new professor (each order item snapshots its owner and split, so past sales, the
 *   ledger and payouts are untouched); enrollments, progress, reviews and Q&A stay with the course;
 * - the old professor's coupons on this course are switched off (they funded them), and the course leaves the
 *   old professor's bundles (a bundle holding a course they no longer own is taken off sale);
 * - its media files go with it unless the old professor uses them in another course.
 * Audited with the reason, both professors and what was changed.
 */
final class TransferCourse
{
    public function __construct(
        private readonly RecordAudit $recordAudit,
        private readonly ConnectionInterface $db,
    ) {}

    public function handle(Course $course, string $toProfessorId, User $staff, string $reason): Course
    {
        return $this->db->transaction(function () use ($course, $toProfessorId, $staff, $reason): Course {
            $course = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $from = $course->professor_profile_id;
            $to = ProfessorProfile::query()->whereKey($toProfessorId)->first();
            if ($to === null || $to->id === $from || $to->status !== ProfessorStatus::Active) {
                throw AuthoringException::invalidTransferTarget();
            }

            $coupons = Coupon::query()
                ->where('course_id', $course->id)
                ->where('professor_profile_id', $from)
                ->where('funded_by', DiscountFunder::Professor)
                ->where('is_active', true)
                ->pluck('id');
            Coupon::query()->whereIn('id', $coupons)->update(['is_active' => false, 'updated_at' => now()]);

            $bundles = Bundle::query()
                ->where('professor_profile_id', $from)
                ->whereHas('courses', fn ($q) => $q->whereKey($course->id))
                ->get();
            foreach ($bundles as $bundle) {
                $bundle->courses()->detach($course->id);
                if ($bundle->status === BundleStatus::Active) {
                    $bundle->forceFill(['status' => BundleStatus::Archived])->save();
                }
            }

            $lessonAssets = Lesson::query()
                ->whereIn('course_version_id', $course->versions()->select('id'))
                ->whereNotNull('media_asset_id')
                ->pluck('media_asset_id')
                ->unique();
            $sharedElsewhere = Lesson::query()
                ->whereIn('media_asset_id', $lessonAssets)
                ->whereNotIn('course_version_id', $course->versions()->select('id'))
                ->pluck('media_asset_id');
            $moved = $lessonAssets->diff($sharedElsewhere)->values();
            MediaAsset::query()->whereIn('id', $moved)->where('professor_profile_id', $from)
                ->update(['professor_profile_id' => $to->id, 'updated_at' => now()]);

            $course->forceFill(['professor_profile_id' => $to->id])->save();

            $this->recordAudit->handle('course.transferred', $course, [
                'reason' => $reason,
                'from_professor_profile_id' => $from,
                'to_professor_profile_id' => $to->id,
                'coupons_deactivated' => $coupons->all(),
                'bundles_changed' => $bundles->pluck('id')->all(),
                'media_assets_moved' => $moved->all(),
            ], causer: $staff);

            return $course;
        });
    }
}
