<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Authoring\Actions\ChangeCourseAvailability;
use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Actions\ChangeUserStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\ModerateReview;
use App\Domain\Learning\Enums\ReviewStatus;
use App\Domain\Learning\Models\Review;
use App\Domain\Moderation\Enums\ReportAction;
use App\Domain\Moderation\Enums\ReportStatus;
use App\Domain\Moderation\Exceptions\ModerationException;
use App\Domain\Moderation\Models\Report;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Professors\Actions\ManageProfessorStanding;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Database\ConnectionInterface;

/**
 * Staff resolve a report (PRD-09 FR-10), which closes every open report on the same item at once:
 * - dismiss: nothing breaks the rules;
 * - hide: a review is hidden, a course taken down (same rules as review moderation and course takedown);
 * - warn: the author gets a warning on their record (audited; the message to them comes with notifications, 1.12);
 * - suspend: a review's author account is suspended; for a course or profile, the professor is suspended.
 * Each action needs the permission its own screen needs (users, professors, catalogue), so a moderator can
 * dismiss, warn and hide reviews but not suspend accounts or take courses down. Staff are never warned or
 * suspended through reports.
 *
 * A report filed while a decision is being saved may stay open on an item already hidden; it can only be
 * dismissed then (accepted: it costs a click, nothing more).
 */
final class ResolveReport
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecordAudit $recordAudit,
        private readonly ModerateReview $moderateReview,
        private readonly ChangeCourseAvailability $courseAvailability,
        private readonly ChangeUserStatus $userStatus,
        private readonly ManageProfessorStanding $professorStanding,
    ) {}

    /** `$note` may be empty only to dismiss; every other action records it as its reason. */
    public function handle(Report $report, ReportAction $action, ?string $note, User $staff): Report
    {
        $this->db->transaction(function () use ($report, $action, $note, $staff): void {
            // Lock the item's open reports first: two staff resolving at once can't both act.
            $open = Report::query()
                ->where('reportable_type', $report->reportable_type)
                ->where('reportable_id', $report->reportable_id)
                ->where('status', ReportStatus::Open)
                ->lockForUpdate()
                ->get();
            if (! $open->contains('id', $report->id)) {
                throw ModerationException::alreadyResolved();
            }

            $target = $report->target();
            if ($action !== ReportAction::Dismiss && ($note === null || $note === '')) {
                throw ModerationException::actionNotAllowed();
            }
            if (! self::allowedActions($report, $target, $staff)[$action->value]) {
                throw ModerationException::actionNotAllowed();
            }

            $this->apply($action, $target, (string) $note, $staff);

            Report::query()->whereKey($open->modelKeys())->update([
                'status' => $action === ReportAction::Dismiss ? ReportStatus::Dismissed : ReportStatus::Actioned,
                'action' => $action,
                'handled_by' => $staff->id,
                'resolution_note' => $note === '' ? null : $note,
                'handled_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recordAudit->handle('report.resolved', $report, [
                'action' => $action->value,
                'note' => $note,
                'target' => ['type' => $report->reportable_type->value, 'id' => $report->reportable_id],
                'reports_closed' => $open->count(),
            ], causer: $staff);
        });

        return $report->refresh();
    }

    /**
     * What this staff member may do with the report now: nothing once it's closed; otherwise each action needs
     * its permission and must still make sense for the item (a hidden review can't be hidden again…).
     *
     * @return array{dismiss: bool, hide: bool, warn: bool, suspend: bool}
     */
    public static function allowedActions(Report $report, Course|Review|ProfessorProfile|null $target, User $staff): array
    {
        $none = ['dismiss' => false, 'hide' => false, 'warn' => false, 'suspend' => false];
        if (! $report->isOpen() || ! $staff->can(Permission::ModerationReports->value)) {
            return $none;
        }

        $author = self::author($target);
        $actsOnOthers = $author !== null && $author->id !== $staff->id;

        return [
            'dismiss' => true,
            'hide' => match (true) {
                $target instanceof Review => $target->status === ReviewStatus::Visible,
                $target instanceof Course => $staff->can(Permission::CatalogManage->value)
                    && ! $target->trashed() && $target->taken_down_at === null && $target->live_version_id !== null,
                default => false,
            },
            'warn' => $actsOnOthers && ! ChangeUserStatus::isStaff($author),
            'suspend' => $actsOnOthers && match (true) {
                $target instanceof Review => $staff->can(Permission::UsersManage->value) && $author->isActive()
                    && ! ChangeUserStatus::isStaff($author),
                $target instanceof Course, $target instanceof ProfessorProfile => $staff->can(Permission::ProfessorsManage->value)
                    && self::profile($target)?->isActive() === true && ! ChangeUserStatus::isStaff($author),
                default => false,
            },
        ];
    }

    /** Who wrote or owns the reported item. */
    public static function author(Course|Review|ProfessorProfile|null $target): ?User
    {
        return match (true) {
            $target instanceof Review => $target->user,
            $target instanceof Course => $target->professor?->user,
            $target instanceof ProfessorProfile => $target->user,
            default => null,
        };
    }

    /**
     * A course report and a profile report on the same professor are different items, so their report locks don't
     * serialise: lock the profile and re-check it, so two staff can't both suspend.
     */
    private function suspendProfessor(Course|ProfessorProfile $target, User $staff, string $note): void
    {
        $profile = ProfessorProfile::query()->whereKey(self::profile($target)?->id)->lockForUpdate()->first();
        if ($profile === null || ! $profile->isActive()) {
            throw ModerationException::actionNotAllowed();
        }
        $this->professorStanding->suspend($profile, $staff, $note);
    }

    private static function profile(Course|ProfessorProfile $target): ?ProfessorProfile
    {
        return $target instanceof Course ? $target->professor : $target;
    }

    private function apply(ReportAction $action, Course|Review|ProfessorProfile|null $target, string $note, User $staff): void
    {
        match (true) {
            $action === ReportAction::Dismiss => null,
            $action === ReportAction::Warn => $this->recordAudit->handle('user.warned', self::author($target), ['note' => $note], causer: $staff),
            $action === ReportAction::Hide && $target instanceof Review => $this->moderateReview->hide($target, $staff, $note),
            $action === ReportAction::Hide && $target instanceof Course => $this->courseAvailability->takeDown($target, $staff, $note),
            $action === ReportAction::Suspend && $target instanceof Review && $target->user !== null => $this->userStatus->handle($target->user, UserStatus::Suspended, $note, $staff),
            $action === ReportAction::Suspend && ($target instanceof Course || $target instanceof ProfessorProfile) => $this->suspendProfessor($target, $staff, $note),
            default => throw ModerationException::actionNotAllowed(),
        };
    }
}
